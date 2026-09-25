<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmResult;
use OCA\Learning\Service\Llm\LlmSession;
use PHPUnit\Framework\TestCase;

/**
 * 5.6.0 Task A1: the consent is bound to the recipient fingerprint of the connection snapshot.
 * A fingerprint that is not stable (same recipient, different hash) silently invalidates every
 * consent; one that is not sensitive (other recipient, same hash) lets data go to a recipient
 * the user never agreed to. Both directions are tested here.
 */
class ValueObjectsTest extends TestCase {
    /** @return array<string, mixed> */
    private function mistral(): array {
        return ['rev' => 1, 'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'mistral',
            'baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'm1', 'processingLocal' => false,
            'allowInternalNetwork' => false];
    }

    public function testRecipientFingerprintIgnoresModelExceptOpenRouter(): void {
        $a = LlmConnection::fromArray(['rev'=>1,'enabled'=>true,'provider'=>'openai_compatible','preset'=>'mistral',
            'baseUrl'=>'https://api.mistral.ai/v1','model'=>'m1','processingLocal'=>false,'allowInternalNetwork'=>false]);
        $b = LlmConnection::fromArray(array_merge($a->toArray(), ['model'=>'m2']));
        $this->assertSame($a->recipientFingerprint(), $b->recipientFingerprint());
        $or1 = LlmConnection::fromArray(array_merge($a->toArray(), ['preset'=>'openrouter','baseUrl'=>'https://openrouter.ai/api/v1',
            'openRouterRouting'=>['order'=>['anthropic'],'allowFallbacks'=>false]]));
        $or2 = LlmConnection::fromArray(array_merge($or1->toArray(), ['model'=>'other/model']));
        $this->assertNotSame($or1->recipientFingerprint(), $or2->recipientFingerprint());
    }

    public function testHostChangeChangesBothFingerprints(): void {
        $a = LlmConnection::fromArray($this->mistral());
        $b = LlmConnection::fromArray(array_merge($this->mistral(), ['baseUrl' => 'https://api.other.example/v1']));
        $this->assertNotSame($a->connectionFingerprint(), $b->connectionFingerprint());
        $this->assertNotSame($a->recipientFingerprint(), $b->recipientFingerprint());
    }

    public function testProcessingLocalChangesRecipientFingerprint(): void {
        $custom = array_merge($this->mistral(), ['preset' => 'custom', 'baseUrl' => 'https://llm.example.org/v1']);
        $a = LlmConnection::fromArray($custom);
        $b = LlmConnection::fromArray(array_merge($custom, ['processingLocal' => true]));
        $this->assertNotSame($a->recipientFingerprint(), $b->recipientFingerprint());
        // The flag describes the recipient, not the endpoint: toggling it must not orphan the API key.
        $this->assertSame($a->connectionFingerprint(), $b->connectionFingerprint());
    }

    public function testUnknownProviderBecomesDisabled(): void {
        $this->assertSame('disabled', LlmConnection::fromArray(['provider'=>'nc_assistant'])->provider);
    }

    public function testOpenRouterWithoutFixedRoutingIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        LlmConnection::fromArray(['provider'=>'openai_compatible','preset'=>'openrouter','baseUrl'=>'https://openrouter.ai/api/v1','model'=>'x']);
    }

    public function testUnknownProviderGetsSafeDefaults(): void {
        $c = LlmConnection::fromArray(['provider' => 'nc_assistant']);
        $this->assertFalse($c->enabled);
        $this->assertSame(0, $c->rev);
        $this->assertFalse($c->allowInternalNetwork);
        $this->assertNull($c->openRouterRouting);
        $this->assertNull($c->adminAck);
    }

    public function testModelChangeDoesNotChangeConnectionFingerprint(): void {
        $a = LlmConnection::fromArray($this->mistral());
        $b = LlmConnection::fromArray(array_merge($this->mistral(), ['model' => 'm2']));
        $this->assertSame($a->connectionFingerprint(), $b->connectionFingerprint());
    }

    public function testFingerprintCanonicalisesHostCasePortAndTrailingSlash(): void {
        $a = LlmConnection::fromArray($this->mistral());
        foreach (['https://API.Mistral.AI/v1', 'https://api.mistral.ai:443/v1', 'https://api.mistral.ai/v1/'] as $url) {
            $b = LlmConnection::fromArray(array_merge($this->mistral(), ['baseUrl' => $url]));
            $this->assertSame($a->connectionFingerprint(), $b->connectionFingerprint(), $url);
            $this->assertSame($a->recipientFingerprint(), $b->recipientFingerprint(), $url);
        }
    }

    public function testPortAndPathChangeTheConnectionFingerprint(): void {
        $a = LlmConnection::fromArray($this->mistral());
        foreach (['https://api.mistral.ai:8443/v1', 'https://api.mistral.ai/v2'] as $url) {
            $b = LlmConnection::fromArray(array_merge($this->mistral(), ['baseUrl' => $url]));
            $this->assertNotSame($a->connectionFingerprint(), $b->connectionFingerprint(), $url);
        }
    }

    public function testPresetChangeChangesBothFingerprints(): void {
        $a = LlmConnection::fromArray($this->mistral());
        $b = LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'custom']));
        $this->assertNotSame($a->connectionFingerprint(), $b->connectionFingerprint());
        $this->assertNotSame($a->recipientFingerprint(), $b->recipientFingerprint());
    }

    public function testFingerprintsAreSha256Hex(): void {
        $a = LlmConnection::fromArray($this->mistral());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->connectionFingerprint());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->recipientFingerprint());
    }

    public function testOpenRouterTargetProviderChangesRecipientFingerprint(): void {
        $base = array_merge($this->mistral(), ['preset' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1',
            'openRouterRouting' => ['order' => ['anthropic'], 'allowFallbacks' => false]]);
        $a = LlmConnection::fromArray($base);
        $b = LlmConnection::fromArray(array_merge($base, ['openRouterRouting' => ['order' => ['openai'], 'allowFallbacks' => false]]));
        $this->assertNotSame($a->recipientFingerprint(), $b->recipientFingerprint());
    }

    public function testOpenRouterRoutingMustNameExactlyOneProviderWithoutFallbacks(): void {
        $base = array_merge($this->mistral(), ['preset' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1']);
        $invalid = [
            ['order' => [], 'allowFallbacks' => false],
            ['order' => ['anthropic', 'openai'], 'allowFallbacks' => false],
            ['order' => [''], 'allowFallbacks' => false],
            ['order' => ['anthropic'], 'allowFallbacks' => true],
            ['order' => ['anthropic']],
        ];
        foreach ($invalid as $routing) {
            try {
                LlmConnection::fromArray(array_merge($base, ['openRouterRouting' => $routing]));
                $this->fail('accepted routing ' . json_encode($routing));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRoundTripPreservesEveryField(): void {
        $in = array_merge($this->mistral(), ['preset' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1',
            'openRouterRouting' => ['order' => ['anthropic'], 'allowFallbacks' => false],
            'adminAck' => ['uid' => 'admin', 'at' => 1700000000]]);
        $a = LlmConnection::fromArray($in);
        $b = LlmConnection::fromArray($a->toArray());
        $this->assertSame($a->toArray(), $b->toArray());
        $this->assertSame(['order' => ['anthropic'], 'allowFallbacks' => false], $b->openRouterRouting);
        $this->assertSame(['uid' => 'admin', 'at' => 1700000000], $b->adminAck);
    }

    public function testHttpsIsRequiredUnlessInternalNetworkIsAllowed(): void {
        $plain = array_merge($this->mistral(), ['preset' => 'custom', 'baseUrl' => 'http://llm.lan:8080/v1']);
        try {
            LlmConnection::fromArray($plain);
            $this->fail('http accepted without allowInternalNetwork');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $ok = LlmConnection::fromArray(array_merge($plain, ['allowInternalNetwork' => true]));
        $this->assertSame('http://llm.lan:8080/v1', $ok->baseUrl);
    }

    public function testUserinfoAndFragmentAreRejected(): void {
        foreach (['https://user:pw@api.mistral.ai/v1', 'https://api.mistral.ai/v1#x', 'ftp://api.mistral.ai/v1', 'api.mistral.ai'] as $url) {
            try {
                LlmConnection::fromArray(array_merge($this->mistral(), ['baseUrl' => $url]));
                $this->fail('accepted ' . $url);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLocalOllamaIsAcceptedWithInternalNetwork(): void {
        $c = LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://localhost:11434',
            'model' => 'llama3', 'allowInternalNetwork' => true]);
        $this->assertSame('ollama', $c->provider);
        $this->assertSame('', $c->preset);
    }

    public function testUnknownOpenAiCompatiblePresetIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'deepseek']));
    }

    public function testRecipientInfoDescribesRegionAndIntermediary(): void {
        $mistral = new LlmSession(LlmConnection::fromArray($this->mistral()), 'alice', LlmPurpose::Chat);
        $r = $mistral->recipient();
        $this->assertSame('eu', $r->region);
        $this->assertFalse($r->isLocal);
        $this->assertFalse($r->intermediary);

        $or = LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1',
            'openRouterRouting' => ['order' => ['anthropic'], 'allowFallbacks' => false]]));
        $r = (new LlmSession($or, 'alice', LlmPurpose::Chat))->recipient();
        $this->assertTrue($r->intermediary);
        $this->assertSame('unknown', $r->region);
        $this->assertStringContainsString('anthropic', $r->name);

        $local = LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'custom', 'processingLocal' => true]));
        $r = (new LlmSession($local, 'alice', LlmPurpose::Chat))->recipient();
        $this->assertTrue($r->isLocal);
        $this->assertSame('local', $r->region);
    }

    public function testOnlyAdminTestMayHaveNoSubject(): void {
        $c = LlmConnection::fromArray($this->mistral());
        $this->assertNull((new LlmSession($c, null, LlmPurpose::AdminTest))->subjectUserId);
        $this->expectException(\InvalidArgumentException::class);
        new LlmSession($c, null, LlmPurpose::Chat);
    }

    public function testPurposeValuesMatchTheSpec(): void {
        $this->assertSame(['chat', 'interview', 'telos_structured', 'note', 'weekly_note', 'course_narrative',
            'memory_compress', 'lernplan', 'story', 'ticket_triage', 'pool_generate', 'explain', 'question_generate',
            'admin_test'], array_map(static fn (LlmPurpose $p) => $p->value, LlmPurpose::cases()));
    }

    public function testRequestAndResultCarryTheirFields(): void {
        $req = new LlmRequest('sys', 'user');
        $this->assertNull($req->temperature);
        $this->assertSame(1200, $req->maxOutputTokens);
        $res = new LlmResult('text', 3, 4, 'm');
        $this->assertSame([3, 4, 'm'], [$res->tokensIn, $res->tokensOut, $res->model]);
    }

    public function testExceptionsCarryCategoryWithoutProviderBody(): void {
        $e = new LlmProviderException('rate_limit', '', 'HTTP 429 redacted');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('rate_limit', $e->category);
        $this->assertSame('rate_limit', $e->getMessage());
        $this->assertSame('HTTP 429 redacted', $e->adminDetail);

        $c = new LlmConsentException();
        $this->assertInstanceOf(LlmProviderException::class, $c);
        $this->assertSame('consent', $c->category);
        $this->assertSame('consent_required', $c->getMessage());
    }

    /**
     * Review A1/A2 (Codex P1): "processed locally" is only a claim an admin can make about a server
     * they run. For a cloud provider it would unlock local-only disclosures while the data leaves.
     */
    public function testProcessingLocalIsOnlyAcceptedForSelfHostedBackends(): void {
        $cloud = [
            $this->mistral(),
            array_merge($this->mistral(), ['preset' => 'openai', 'baseUrl' => 'https://api.openai.com/v1']),
            ['enabled' => true, 'provider' => 'gemini', 'baseUrl' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'g'],
            ['enabled' => true, 'provider' => 'anthropic', 'baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'c'],
        ];
        foreach ($cloud as $a) {
            try {
                LlmConnection::fromArray(array_merge($a, ['processingLocal' => true]));
                $this->fail('processingLocal accepted for ' . $a['provider'] . '/' . ($a['preset'] ?? ''));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $ollama = LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://localhost:11434',
            'model' => 'llama3', 'allowInternalNetwork' => true, 'processingLocal' => true]);
        $this->assertTrue($ollama->processingLocal);
        $custom = LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'custom', 'processingLocal' => true]));
        $this->assertTrue($custom->processingLocal);
    }

    /**
     * Review A1/A2: the fingerprint host must be the host the HTTP client connects to. parse_url
     * accepts hosts that curl reads differently (backslash, spaces), so those URLs are rejected.
     */
    public function testUrlsThatParsersReadDifferentlyAreRejected(): void {
        $bad = [
            'https://evil.example\\.api.mistral.ai/v1',
            'https://a b/v1',
            'https://bücher.example/v1',
            'https://api.mistral.ai:0/v1',
            "https://api.mistral.ai/v\xff",
            "https://api.mistral.ai/v\n1",
            'https://api.mistral.ai/v1?',
        ];
        foreach ($bad as $url) {
            try {
                LlmConnection::fromArray(array_merge($this->mistral(), ['preset' => 'custom', 'baseUrl' => $url]));
                $this->fail('accepted ' . json_encode($url, JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Every URL 5.5 OllamaProvider accepted must still load, or an upgrade silently turns AI off. */
    public function testTypicalLegacyOllamaUrlsStayValid(): void {
        foreach (['http://localhost:11434', 'http://127.0.0.1:11434', 'http://ollama:11434/', 'http://host.docker.internal:11434',
            'http://10.0.0.5:11434', 'http://[::1]:11434', 'http://ollama.lan:11434'] as $url) {
            $c = LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => $url, 'model' => 'llama3',
                'allowInternalNetwork' => true]);
            $this->assertSame('ollama', $c->provider, $url);
        }
    }

    /** Review A1/A2 (Codex P2): equivalent spellings of one host must not orphan keys or consents. */
    public function testIpv6AndTrailingDotSpellingsShareFingerprints(): void {
        $custom = array_merge($this->mistral(), ['preset' => 'custom']);
        $pairs = [
            ['https://[2001:db8::1]/v1', 'https://[2001:0DB8:0:0:0:0:0:1]/v1'],
            ['https://api.mistral.ai/v1', 'https://api.mistral.ai./v1'],
        ];
        foreach ($pairs as [$x, $y]) {
            $a = LlmConnection::fromArray(array_merge($custom, ['baseUrl' => $x]));
            $b = LlmConnection::fromArray(array_merge($custom, ['baseUrl' => $y]));
            $this->assertSame($a->connectionFingerprint(), $b->connectionFingerprint(), $y);
            $this->assertSame($a->recipientFingerprint(), $b->recipientFingerprint(), $y);
        }
    }

    /**
     * Review A1/A2 (Codex P2): json_encode fails on invalid UTF-8; cast to '' every such
     * configuration would share one fingerprint and therefore one API key.
     */
    public function testInvalidUtf8IsRejectedAndNeverHashedToACommonValue(): void {
        foreach ([['model' => "m\xff"], ['preset' => 'openrouter', 'baseUrl' => 'https://openrouter.ai/api/v1',
            'openRouterRouting' => ['order' => ["anth\xffropic"], 'allowFallbacks' => false]]] as $patch) {
            try {
                LlmConnection::fromArray(array_merge($this->mistral(), $patch));
                $this->fail('accepted invalid UTF-8');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // The public constructor skips validation; the hash must still refuse instead of hashing ''.
        $raw = new LlmConnection(1, true, 'openai_compatible', 'openrouter', 'https://openrouter.ai/api/v1', "m\xff",
            false, false, ['order' => ['anthropic'], 'allowFallbacks' => false], null);
        $this->expectException(\JsonException::class);
        $raw->recipientFingerprint();
    }

    /** OpenRouter's model decides the recipient even if a snapshot was built without fromArray(). */
    public function testOpenRouterFingerprintAlwaysIncludesTheModel(): void {
        $mk = fn (string $model) => new LlmConnection(1, true, 'openai_compatible', 'openrouter', 'https://openrouter.ai/api/v1',
            $model, false, false, null, null);
        $this->assertNotSame($mk('a/one')->recipientFingerprint(), $mk('b/two')->recipientFingerprint());
    }
}
