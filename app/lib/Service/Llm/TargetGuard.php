<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Target check for every model request (spec 5.3): URL shape, DNS resolution, and a refusal of
 * internal addresses unless the connection explicitly allows the internal network. The returned
 * IP is what LlmHttpClient pins the request to (CURLOPT_RESOLVE), so the address that was checked
 * is the address that is connected to — no second lookup between check and connect (DNS rebinding).
 *
 * Why the app checks itself instead of relying on Nextcloud (verified against the NC 33 image,
 * lib/private/Http/Client/{Client,ClientService,DnsPinMiddleware}.php and lib/private/Net/IpAddressClassifier.php):
 * - Client::isLocalAddressAllowed() returns true if the request option nextcloud.allow_local_address
 *   OR the global system config allow_local_remote_servers is set. Then Client::preventLocalAddress()
 *   and DnsPinMiddleware both return early: no check, no pin. DnsPinMiddleware is also not installed
 *   at all when the system config dns_pinning is false. On many self-hosted instances (Ollama next
 *   door) allow_local_remote_servers is on — so without this guard, a cloud connection whose host
 *   starts resolving to a LAN address would be followed. This guard is independent of both flags.
 * - When NC's pinning IS active (allow_local_address false), it resolves again on its own and
 *   appends its CURLOPT_RESOLVE entries after ours, and libcurl lets the later entry win. That is
 *   why LlmHttpClient also sets CURLOPT_CONNECT_TO to the checked IP literal: connect-to is applied
 *   before any name resolution, so neither NC's entries nor an HTTP proxy's own lookup (the proxy
 *   receives CONNECT <ip>:<port>) can move the connection away from the checked address.
 * - NC's IpAddressClassifier is @internal, so its rules are re-implemented here (see isInternal())
 *   rather than depended on: FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE, 100.64.0.0/10, 192.0.0.0/24,
 *   IPv4 embedded in IPv6 (mapped, compatible, NAT64, Teredo) checked as IPv4, 64:ff9b:1::/48 local.
 *   Stricter than NC: multicast, 198.18.0.0/15, 6to4-embedded IPv4, site-local fec0::/10.
 *   Every resolved address is checked; one internal address rejects the whole target.
 *
 * Known limits: plain http (only allowed with allowInternalNetwork, where every address is allowed
 * anyway) through an HTTP proxy is sent as an absolute URL and resolved by the proxy. PHP has no
 * getaddrinfo(): IPv6 comes from DNS AAAA records only, so a host defined solely by an IPv6 line in
 * /etc/hosts is "not resolved" — this fails closed (use the IPv6 literal in the base URL instead).
 *
 * Hosts must be ASCII (IDNs as punycode) and without trailing dot: CURLOPT_RESOLVE only applies
 * when curl looks up exactly the pinned name; any other spelling would fall back to an unpinned lookup.
 */
final class TargetGuard {
    /** @var callable(string): list<string> */
    private $resolver;

    /** @param (callable(string): list<string>)|null $dnsResolver hostname → IP addresses; null = system resolver */
    public function __construct(?callable $dnsResolver = null) {
        $this->resolver = $dnsResolver ?? [self::class, 'systemResolve'];
    }

    /**
     * @return array{host: string, ip: string, port: int, scheme: string}
     * @throws LlmProviderException category config — message never contains the resolved addresses
     */
    public function resolve(string $url, bool $allowInternalNetwork): array {
        // Same parser-ambiguity rule as LlmConnection::validateBaseUrl: FILTER_VALIDATE_URL rejects
        // backslashes, spaces, control characters and non-ASCII hosts that parse_url and curl read differently.
        $u = filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('//u', $url) === 1 ? parse_url($url) : false;
        if (!is_array($u) || !isset($u['scheme'], $u['host']) || $u['host'] === '') {
            throw self::fail('Target URL must be an absolute URL');
        }
        $scheme = strtolower($u['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInternalNetwork)) {
            throw self::fail('Target URL must use https');
        }
        if (isset($u['user']) || isset($u['pass']) || isset($u['fragment'])) {
            throw self::fail('Target URL must not contain credentials or a fragment');
        }
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw self::fail('Target URL has an invalid port');
        }

        $host = strtolower($u['host']);
        if (str_ends_with($host, '.')) {
            throw self::fail('Target host must not end with a dot');
        }
        $literal = self::ipLiteral($host);
        if ($literal !== null) {
            $ips = [$literal];
        } else {
            if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
                throw self::fail('Target host must be ASCII');
            }
            // A numeric last label makes curl (like browsers) parse the host as an IPv4 literal in
            // shorthand/octal/hex form (127.1, 0x7f.1, 2130706433): no lookup, so no pin applies, and
            // the system resolver may read it differently. Only the dotted-quad form is accepted.
            if (preg_match('/(^|\.)(0x[0-9a-f]*|[0-9]+)$/', $host) === 1) {
                throw self::fail('Target host must be a hostname or a dotted-quad IPv4 address');
            }
            $ips = ($this->resolver)($host);
        }

        $valid = [];
        foreach ($ips as $ip) {
            $canonical = is_string($ip) ? self::canonicalIp($ip) : null;
            if ($canonical === null) {
                continue;
            }
            if (!$allowInternalNetwork && self::isInternal($canonical)) {
                throw self::fail('Target resolves to an internal address; enable the internal network for this connection');
            }
            $valid[] = $canonical;
        }
        if ($valid === []) {
            throw self::fail('Target host could not be resolved');
        }
        $v4 = array_values(array_filter($valid, fn(string $ip) => !str_contains($ip, ':')));
        return ['host' => $host, 'ip' => $v4[0] ?? $valid[0], 'port' => $port, 'scheme' => $scheme];
    }

    /** True if $host (as returned by resolve()) is an IP literal, which curl never resolves. */
    public static function isIpLiteral(string $host): bool {
        return self::ipLiteral($host) !== null;
    }

    /**
     * System resolver: getaddrinfo-based IPv4 (follows CNAMEs, /etc/hosts, container DNS) plus AAAA.
     *
     * @return list<string>
     */
    public static function systemResolve(string $host): array {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** Is this (canonical) address loopback, private, link-local, ULA, reserved, multicast or unspecified? */
    public static function isInternal(string $ip): bool {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);
            if ($embedded === 'local') {
                return true;
            }
            if ($embedded !== null) {
                return self::isInternalV4($embedded);
            }
            foreach (['fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '100::/64'] as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return true;
                }
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return self::isInternalV4($packed);
    }

    private static function isInternalV4(string $packed): bool {
        foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
            '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }
        $text = inet_ntop($packed);
        return $text === false
            || filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** Packed IPv4 embedded in an IPv6 address, 'local' for translation ranges with unknown encoding, or null. */
    private static function embeddedIpv4(string $packed): ?string {
        if (self::inCidr($packed, '64:ff9b:1::/48')) {
            return 'local'; // RFC 8215: no assumption about the encoding is possible (as in NC)
        }
        if (self::inCidr($packed, '::ffff:0:0/96') || self::inCidr($packed, '64:ff9b::/96') || self::inCidr($packed, '::/96')) {
            return substr($packed, 12, 4); // mapped, NAT64, IPv4-compatible (incl. :: and ::1 → 0.0.0.x)
        }
        if (self::inCidr($packed, '2001::/32')) {
            return substr($packed, 12, 4) ^ "\xFF\xFF\xFF\xFF"; // Teredo client address
        }
        if (self::inCidr($packed, '2002::/16')) {
            return substr($packed, 2, 4); // 6to4
        }
        return null;
    }

    private static function inCidr(string $packed, string $cidr): bool {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = inet_pton($net);
        if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
    }

    /** Canonical text form of an IP address (RFC 5952 for IPv6), or null if it is none. */
    private static function canonicalIp(string $ip): ?string {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $text = inet_ntop($packed);
        return $text === false ? null : $text;
    }

    private static function ipLiteral(string $host): ?string {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return self::canonicalIp(substr($host, 1, -1));
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? self::canonicalIp($host) : null;
    }

    private static function fail(string $adminDetail): LlmProviderException {
        return new LlmProviderException('config', '', $adminDetail);
    }
}
