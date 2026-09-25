<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\TargetGuard;
use PHPUnit\Framework\TestCase;

/**
 * 5.6.0 Task A3 (Review Focus 4, DNS rebinding): before every model request the target host is
 * resolved and checked by the app itself. A host that resolves to a LAN/loopback/link-local/ULA
 * address is refused unless the connection explicitly allows the internal network, no matter what
 * Nextcloud's global allow_local_remote_servers says. The checked IP is returned for pinning.
 */
class TargetGuardTest extends TestCase {

    public function testHostResolvingToPrivateIpIsRejectedWithoutInternalFlag(): void {
        $guard = new TargetGuard(fn(string $h) => ['192.168.1.20']);
        $this->expectException(LlmProviderException::class);
        $guard->resolve('https://llm.example.com/v1', false);
    }

    public function testHostResolvingToPrivateIpAllowedWithInternalFlag(): void {
        $guard = new TargetGuard(fn(string $h) => ['192.168.1.20']);
        $this->assertSame('192.168.1.20', $guard->resolve('http://llm.example.com:11434', true)['ip']);
    }

    public function testUserinfoRejected(): void {
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7']);
        $this->assertConfigError(fn() => $guard->resolve('https://u:p@llm.example.com/v1', false));
        $this->assertConfigError(fn() => $guard->resolve('https://u@llm.example.com/v1', true));
    }

    public function testIpv6UlaRejected(): void {
        $guard = new TargetGuard(fn(string $h) => ['fd00::1']);
        $this->assertConfigError(fn() => $guard->resolve('https://llm.example.com/v1', false));
        $literal = new TargetGuard(fn(string $h) => $this->fail('IP literal must not be resolved'));
        $this->assertConfigError(fn() => $literal->resolve('https://[fd00::1]/v1', false));
    }

    public function testPublicHostReturnsPinnableTarget(): void {
        $guard = new TargetGuard(fn(string $h) => $h === 'api.mistral.ai' ? ['203.0.113.7'] : []);
        $this->assertSame(
            ['host' => 'api.mistral.ai', 'ip' => '203.0.113.7', 'port' => 443, 'scheme' => 'https'],
            $guard->resolve('https://API.Mistral.ai/v1/chat/completions', false),
        );
    }

    public function testExplicitPortIsKept(): void {
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7']);
        $this->assertSame(8443, $guard->resolve('https://llm.example.com:8443/v1', false)['port']);
    }

    public function testHttpRejectedWithoutInternalFlag(): void {
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7']);
        $this->assertConfigError(fn() => $guard->resolve('http://api.example.com/v1', false));
        $this->assertSame(80, $guard->resolve('http://api.example.com/v1', true)['port']);
    }

    public function testNonHttpSchemeRejectedEvenWithInternalFlag(): void {
        $guard = new TargetGuard(fn(string $h) => ['192.168.1.20']);
        $this->assertConfigError(fn() => $guard->resolve('ftp://llm.example.com/v1', true));
        $this->assertConfigError(fn() => $guard->resolve('file:///etc/passwd', true));
        $this->assertConfigError(fn() => $guard->resolve('gopher://llm.example.com/', true));
    }

    public function testFragmentRejected(): void {
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7']);
        $this->assertConfigError(fn() => $guard->resolve('https://llm.example.com/v1#frag', false));
    }

    public function testParserAmbiguousUrlsRejected(): void {
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7']);
        foreach ([
            'https://evil.example\\@llm.example.com/v1',
            'https://llm.example.com /v1',
            "https://llm.example.com\n/v1",
            'https://llüm.example.com/v1',
            'https:///v1',
            'llm.example.com/v1',
            '',
        ] as $url) {
            $this->assertConfigError(fn() => $guard->resolve($url, true), $url);
        }
    }

    public function testEmptyResolutionRejected(): void {
        $guard = new TargetGuard(fn(string $h) => []);
        $this->assertConfigError(fn() => $guard->resolve('https://nx.example.com/v1', true));
        $garbage = new TargetGuard(fn(string $h) => ['not-an-ip']);
        $this->assertConfigError(fn() => $garbage->resolve('https://nx.example.com/v1', true));
    }

    /** @return array<string, array{string}> */
    public static function localAddresses(): array {
        return [
            'rfc1918 10/8' => ['10.1.2.3'],
            'rfc1918 172.16/12' => ['172.20.0.2'],
            'rfc1918 192.168/16' => ['192.168.178.1'],
            'loopback' => ['127.0.0.1'],
            'loopback 127/8' => ['127.8.8.8'],
            'unspecified' => ['0.0.0.0'],
            'this network' => ['0.1.2.3'],
            'link-local / metadata' => ['169.254.169.254'],
            'cgnat / tailnet' => ['100.100.100.100'],
            'ietf 192.0.0/24' => ['192.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 ula fc00' => ['fc00::5'],
            'ipv6 ula fd' => ['fd12:3456:789a::1'],
            'ipv4-mapped private' => ['::ffff:192.168.1.1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-compatible loopback' => ['::127.0.0.1'],
            'nat64 embedded private' => ['64:ff9b::c0a8:0101'],
            'rfc8215 translation' => ['64:ff9b:1::1'],
        ];
    }

    /** @dataProvider localAddresses */
    public function testLocalAddressesRejectedWithoutInternalFlag(string $ip): void {
        $guard = new TargetGuard(fn(string $h) => [$ip]);
        $this->assertConfigError(fn() => $guard->resolve('https://llm.example.com/v1', false), $ip);
        // the same address is fine once the admin explicitly allowed the internal network
        $this->assertSame(inet_ntop(inet_pton($ip)), $guard->resolve('https://llm.example.com/v1', true)['ip']);
    }

    public function testLocalIpLiteralHostRejectedWithoutResolver(): void {
        $guard = new TargetGuard(fn(string $h) => $this->fail('IP literal must not be resolved'));
        $this->assertConfigError(fn() => $guard->resolve('https://127.0.0.1/v1', false));
        $this->assertConfigError(fn() => $guard->resolve('https://[::1]:8080/v1', false));
        $this->assertSame('203.0.113.9', $guard->resolve('https://203.0.113.9/v1', false)['ip']);
        $this->assertSame('2001:db8::7', $guard->resolve('https://[2001:DB8::7]/v1', false)['ip']);
    }

    public function testMixedPublicAndPrivateResolutionRejected(): void {
        // a rebinding answer set: one public address to pass a naive "first IP" check, one LAN address
        $guard = new TargetGuard(fn(string $h) => ['203.0.113.7', '192.168.1.20']);
        $this->assertConfigError(fn() => $guard->resolve('https://llm.example.com/v1', false));
    }

    public function testPrefersIpv4AndChecksEveryAddress(): void {
        $guard = new TargetGuard(fn(string $h) => ['2001:db8::1', '203.0.113.7']);
        $this->assertSame('203.0.113.7', $guard->resolve('https://llm.example.com/v1', false)['ip']);
        $v6only = new TargetGuard(fn(string $h) => ['2001:db8::1']);
        $this->assertSame('2001:db8::1', $v6only->resolve('https://llm.example.com/v1', false)['ip']);
    }

    public function testErrorMessageHasNoResolvedAddress(): void {
        $guard = new TargetGuard(fn(string $h) => ['192.168.1.20']);
        try {
            $guard->resolve('https://llm.example.com/v1', false);
            $this->fail('expected LlmProviderException');
        } catch (LlmProviderException $e) {
            $this->assertSame('config', $e->category);
            $this->assertStringNotContainsString('192.168.1.20', $e->getMessage());
        }
    }

    private function assertConfigError(callable $fn, string $label = ''): void {
        try {
            $fn();
        } catch (LlmProviderException $e) {
            $this->assertSame('config', $e->category, $label);
            return;
        }
        $this->fail('expected LlmProviderException(config) ' . $label);
    }
}
