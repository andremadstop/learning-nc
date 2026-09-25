<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;

/**
 * The one HTTP path for model requests (spec 2.2 / 5.3): target check + DNS pinning, no
 * redirects, no retries, request body ≤ 200 KB, response ≤ 2 MB, failures as a category.
 *
 * How this fits Nextcloud's own client (verified against the NC 33 image; details in TargetGuard):
 * - Client::buildRequestOptions() merges its defaults with ours via a SHALLOW array_merge:
 *   - our allow_redirects=false replaces NC's on_redirect callback — nothing to re-check, as no
 *     redirect is followed at all;
 *   - our `curl` array replaces NC's whole default `curl` array, so CURLOPT_HTTP_VERSION (2TLS) is
 *     set here again to keep NC's behaviour;
 *   - nextcloud.allow_local_address is recomputed by NC as ours OR allow_local_remote_servers.
 * - http_errors=false is required: NC leaves Guzzle's default (true), under which a 401/429/503
 *   throws a Guzzle exception instead of returning a response, and every provider error would
 *   collapse into "network".
 * - Pinning: CURLOPT_CONNECT_TO host:port:ip:port with the IP TargetGuard checked is authoritative
 *   (verified with libcurl 8.14 in the NC image: it wins over later CURLOPT_RESOLVE entries, which
 *   NC's DnsPinMiddleware appends, and an HTTP proxy receives CONNECT <ip>:<port>). TLS still
 *   verifies the certificate against the host name. CURLOPT_RESOLVE host:port:ip is sent as well.
 * - Response size: curl aborts on a larger Content-Length (MAXFILESIZE) and, for any curl version,
 *   the progress callback aborts once more than 2 MB arrived. Transparent decompression is off
 *   (Accept-Encoding identity, decode_content false; a compressed answer is refused), since a
 *   small gzip body could otherwise expand far beyond the limit after these checks.
 * - No Guzzle classes are referenced (not in PHPStan's scan paths, not a public NC API): transport
 *   errors are classified by LocalServerException (OCP) and the cURL error number in the message;
 *   the message itself is never passed on (it can contain the URL and resolved addresses).
 */
final class LlmHttpClient {
    public const CONNECT_TIMEOUT_SEC = 5;
    public const MAX_REQUEST_BYTES = 200 * 1024;
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const ADMIN_DETAIL_CHARS = 200;

    public function __construct(
        private IClientService $clientService,
        private TargetGuard $targetGuard,
    ) {
    }

    /**
     * Sends exactly one POST with a JSON body. Never retries.
     *
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{status: int, json: ?array<mixed>, bodyBytes: int}
     * @throws LlmProviderException
     */
    public function postJson(LlmConnection $c, string $url, array $headers, array $body, int $timeoutSec): array {
        try {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LlmProviderException('bad_request', '', 'Request body could not be encoded as JSON');
        }
        if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
            throw new LlmProviderException('bad_request', '', 'Request body exceeds 200 KB');
        }

        $target = $this->targetGuard->resolve($url, $c->allowInternalNetwork);

        $curl = [
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2TLS,
            // curl aborts on a larger Content-Length, and (curl ≥ 8.4) during the transfer
            \CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            // independent of the curl version: abort (cURL error 42) once more than the limit arrived
            \CURLOPT_NOPROGRESS => false,
            \CURLOPT_XFERINFOFUNCTION => static fn($handle, $dlTotal, $dlNow, $ulTotal, $ulNow): int
                => $dlNow > self::MAX_RESPONSE_BYTES ? 1 : 0,
        ];
        if (!TargetGuard::isIpLiteral($target['host'])) {
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $hostPort = $target['host'] . ':' . $target['port'];
            $curl[\CURLOPT_RESOLVE] = [$hostPort . ':' . $ip];
            $curl[\CURLOPT_CONNECT_TO] = [$hostPort . ':' . $ip . ':' . $target['port']];
        }

        $options = [
            'headers' => array_merge(
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $headers,
                ['Accept-Encoding' => 'identity'],
            ),
            'body' => $encoded,
            'timeout' => $timeoutSec,
            'connect_timeout' => self::CONNECT_TIMEOUT_SEC,
            'allow_redirects' => false,
            'http_errors' => false,
            'decode_content' => false,
            'curl' => $curl,
            'nextcloud' => ['allow_local_address' => $c->allowInternalNetwork],
        ];

        try {
            $response = $this->clientService->newClient()->post($url, $options);
        } catch (LocalServerException) {
            throw new LlmProviderException('config', '', 'Nextcloud refused the target as a local address');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $curlError = preg_match('/cURL error (\d+)\b/', $msg, $m) === 1 ? (int)$m[1] : null;
            [$category, $detail] = match (true) {
                $curlError === 28 || ($curlError === null && preg_match('/timed out/i', $msg) === 1)
                    => ['timeout', 'Request timed out'],
                $curlError === 42, $curlError === 63 => ['bad_request', 'Response exceeds 2 MB'],
                default => ['network', 'Transport error'],
            };
            throw new LlmProviderException($category, '', $detail . ($curlError !== null ? " (cURL error $curlError)" : ''));
        }

        $status = $response->getStatusCode();
        $encoding = strtolower(trim($response->getHeader('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new LlmProviderException('bad_request', '', 'Provider sent a compressed response despite Accept-Encoding: identity');
        }
        $raw = self::readLimited($response->getBody());
        if ($raw === null) {
            throw new LlmProviderException('bad_request', '', 'Response exceeds 2 MB');
        }

        if ($status >= 200 && $status < 300) {
            $json = json_decode($raw, true);
            return ['status' => $status, 'json' => is_array($json) ? $json : null, 'bodyBytes' => strlen($raw)];
        }

        $category = match (true) {
            $status === 401, $status === 403 => 'auth',
            $status === 429 => 'rate_limit',
            $status === 503, $status === 529 => 'overloaded',
            $status === 400, $status === 404, $status === 422 => 'bad_request',
            default => 'network',
        };
        throw new LlmProviderException($category, '', self::adminDetail($raw, self::secretsOf($url, $headers)));
    }

    /**
     * The credentials this request carries (auth/key/token headers, key-like query parameters), so an
     * echo of them is redacted even when it is short or contains punctuation the generic pattern misses.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function secretsOf(string $url, array $headers): array {
        $sensitive = '/auth|key|token|secret|sig|cookie|password/i';
        $secrets = [];
        foreach ($headers as $name => $value) {
            if (preg_match($sensitive, (string)$name) === 1) {
                $secrets[] = $value;
                $secrets[] = (string)preg_replace('/^\S+\s+/', '', $value); // without "Bearer " etc.
            }
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        foreach ($query as $name => $value) {
            if (is_string($value) && preg_match($sensitive, (string)$name) === 1) {
                $secrets[] = $value;
                $secrets[] = rawurlencode($value);
            }
        }
        $secrets = array_values(array_unique(array_filter($secrets, fn(string $s) => strlen(trim($s)) >= 4)));
        usort($secrets, fn(string $a, string $b) => strlen($b) <=> strlen($a)); // longest first
        return $secrets;
    }

    /**
     * @param mixed $body string, stream resource or null (IResponse::getBody())
     * @return string|null null when the body is larger than MAX_RESPONSE_BYTES
     */
    private static function readLimited(mixed $body): ?string {
        if (is_resource($body)) {
            $raw = stream_get_contents($body, self::MAX_RESPONSE_BYTES + 1);
            fclose($body);
            $raw = $raw === false ? '' : $raw;
        } else {
            $raw = is_string($body) ? $body : '';
        }
        return strlen($raw) > self::MAX_RESPONSE_BYTES ? null : $raw;
    }

    /** Short redacted provider error text for the admin connection test — never the full body. */
    /** @param list<string> $secrets */
    private static function adminDetail(string $raw, array $secrets): string {
        $text = $raw;
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $error = $json['error'] ?? null;
            $candidates = [is_array($error) ? ($error['message'] ?? null) : $error, $json['message'] ?? null, $json['detail'] ?? null];
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $text = $candidate;
                    break;
                }
            }
        }
        return self::redact($text, $secrets);
    }

    /**
     * Redact the request's own credentials and key-like sequences FIRST, then cut: cutting first
     * could leave a short, unmatched key fragment.
     *
     * @param list<string> $secrets
     */
    private static function redact(string $text, array $secrets): string {
        $text = $secrets === [] ? $text : str_replace($secrets, '[redacted]', $text);
        $text = mb_scrub($text, 'UTF-8');
        $text = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = (string)preg_replace('/[A-Za-z0-9_\-]{24,}/u', '[redacted]', $text);
        return trim(mb_substr(trim($text), 0, self::ADMIN_DETAIL_CHARS, 'UTF-8'));
    }
}
