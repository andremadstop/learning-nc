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
 * - Pinning: we always send CURLOPT_RESOLVE host:port:ip with the IP TargetGuard checked. If NC's
 *   DnsPinMiddleware is active (allow_local_address false, dns_pinning on) it appends its own,
 *   likewise checked, entries which libcurl lets win; otherwise ours is the only pin — exactly
 *   the case of Review Focus 4 (allow_local_remote_servers on, our connection not internal).
 * - No Guzzle classes are referenced (not in PHPStan's scan paths, not a public NC API): transport
 *   errors are classified by LocalServerException (OCP) and the cURL error number in the message.
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
        ];
        if (!TargetGuard::isIpLiteral($target['host'])) {
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $curl[\CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ip];
        }

        $options = [
            'headers' => array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers),
            'body' => $encoded,
            'timeout' => $timeoutSec,
            'connect_timeout' => self::CONNECT_TIMEOUT_SEC,
            'allow_redirects' => false,
            'http_errors' => false,
            'curl' => $curl,
            'nextcloud' => ['allow_local_address' => $c->allowInternalNetwork],
        ];

        try {
            $response = $this->clientService->newClient()->post($url, $options);
        } catch (LocalServerException) {
            throw new LlmProviderException('config', '', 'Nextcloud refused the target as a local address');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $category = match (true) {
                preg_match('/cURL error 28\b|timed out/i', $msg) === 1 => 'timeout',
                preg_match('/cURL error 63\b/', $msg) === 1 => 'bad_request',
                default => 'network',
            };
            throw new LlmProviderException($category, '', self::redact($msg));
        }

        $status = $response->getStatusCode();
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
        throw new LlmProviderException($category, '', self::adminDetail($raw));
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
    private static function adminDetail(string $raw): string {
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
        return self::redact($text);
    }

    /** Redact key-like sequences FIRST, then cut: cutting first could leave a short, unmatched key fragment. */
    private static function redact(string $text): string {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = (string)preg_replace('/[A-Za-z0-9_\-]{24,}/u', '[redacted]', $text);
        return trim(mb_substr(trim($text), 0, self::ADMIN_DETAIL_CHARS, 'UTF-8'));
    }
}
