<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentRecipientChangedException;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task B1 (spec 3.3): a consent counts only for the current consent text AND the recipient
 * it was given for. There is no exception for local processing: a different recipient fingerprint
 * is a different recipient, whatever processingLocal says.
 */
class ConsentServiceTest extends TestCase {
    private UserTelosMapper&MockObject $mapper;
    private AuditService&MockObject $audit;
    private ?UserTelos $row = null;
    private FakeAppConfigValues $values;

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $this->mapper = $this->createMock(UserTelosMapper::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->mapper->method('findByUserIdOrNull')->willReturnCallback(fn () => $this->row);
    }

    private function service(): ConsentService {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        return new ConsentService($this->mapper, $this->audit, $appConfig);
    }

    private function gemini(): LlmConnection {
        return ConnectionStore::legacyGemini(true);
    }

    private function ollama(string $url, bool $local = true): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => $url, 'model' => 'llama3',
            'allowInternalNetwork' => true, 'processingLocal' => $local,
        ]);
    }

    private function storedConsent(?string $version, ?string $fingerprint, ?string $source = 'dialog'): void {
        $row = new UserTelos();
        $row->setId(7);
        $row->setUserId('alice');
        $row->setAiConsentVersion($version);
        $row->setAiConsentFingerprint($fingerprint);
        $row->setAiConsentSource($source);
        $row->setAiConsentAt($fingerprint === null ? null : 1_700_000_000);
        $row->setConsentEpoch(4);
        $this->row = $row;
    }

    private function current(): string {
        return $this->service()->currentVersion();
    }

    // ---- data file ---------------------------------------------------------------------------

    public function testCurrentVersionReadsTheDataFile(): void {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../../../data/ai-consent.json'), true);
        $this->assertIsArray($data);
        // Quoted in the JSON: an unquoted 3.0 would decode as a float and compare as "3".
        $this->assertIsString($data['version']);
        $this->assertSame('3.0', $data['version']);
        $this->assertSame('3.0', $this->current());
    }

    // ---- validity ----------------------------------------------------------------------------

    public function testValidOnlyWithCurrentVersionAndSameRecipient(): void {
        $c = $this->gemini();
        $this->storedConsent($this->current(), $c->recipientFingerprint());

        $this->assertTrue($this->service()->isValidFor('alice', $c));
        $this->assertSame(['valid' => true, 'reason' => 'none', 'source' => 'dialog'], $this->service()->state('alice', $c));
    }

    public function testLocalProcessingIsNoExceptionForAnotherRecipient(): void {
        $this->storedConsent($this->current(), $this->ollama('http://ollama.lan:11434')->recipientFingerprint());
        $other = $this->ollama('http://other-ollama.lan:11434');

        $this->assertFalse($this->service()->isValidFor('alice', $other));
        $this->assertSame('recipient_changed', $this->service()->state('alice', $other)['reason']);
    }

    public function testNoRowMeansNever(): void {
        $this->row = null;
        $this->assertFalse($this->service()->isValidFor('alice', $this->gemini()));
        $this->assertSame(['valid' => false, 'reason' => 'never', 'source' => null], $this->service()->state('alice', $this->gemini()));
    }

    public function testRowWithoutConsentMeansNever(): void {
        $this->storedConsent(null, null, null);
        $this->assertSame('never', $this->service()->state('alice', $this->gemini())['reason']);
    }

    /** Upgraded users must get the "revised" header (reason version), not "recipient changed". */
    public function testLegacyRowWithOldVersionAndNoFingerprintIsVersion(): void {
        $this->storedConsent('2.0', null, null);
        $c = $this->gemini();

        $this->assertFalse($this->service()->isValidFor('alice', $c));
        $this->assertSame('version', $this->service()->state('alice', $c)['reason']);
    }

    public function testOldVersionWinsOverChangedRecipient(): void {
        $this->storedConsent('2.0', $this->ollama('http://ollama.lan:11434')->recipientFingerprint());
        $this->assertSame('version', $this->service()->state('alice', $this->gemini())['reason']);
    }

    public function testChangedRecipientWithCurrentVersion(): void {
        $this->storedConsent($this->current(), $this->ollama('http://ollama.lan:11434')->recipientFingerprint());
        $this->assertSame('recipient_changed', $this->service()->state('alice', $this->gemini())['reason']);
    }

    /**
     * A current version without any recipient can only come from the version-only endpoint of
     * 5.6 (replaced in B6). No recipient was ever agreed to, so it reads as "never".
     */
    public function testCurrentVersionWithoutFingerprintIsNever(): void {
        $this->storedConsent($this->current(), null);
        $this->assertFalse($this->service()->isValidFor('alice', $this->gemini()));
        $this->assertSame('never', $this->service()->state('alice', $this->gemini())['reason']);
    }

    public function testDisabledConnectionIsNeverValid(): void {
        $enabled = $this->gemini();
        $this->storedConsent($this->current(), $enabled->recipientFingerprint());
        $off = ConnectionStore::legacyGemini(false);
        // Same recipient, only switched off.
        $this->assertSame($enabled->recipientFingerprint(), $off->recipientFingerprint());

        $this->assertFalse($this->service()->isValidFor('alice', $off));
        $state = $this->service()->state('alice', $off);
        $this->assertFalse($state['valid']);
        // Nothing to consent to while AI is off.
        $this->assertSame('none', $state['reason']);
    }

    /** state() and isValidFor() must never disagree. */
    public function testStateAgreesWithIsValidFor(): void {
        $gemini = $this->gemini();
        $ollama = $this->ollama('http://ollama.lan:11434');
        $cases = [
            [null, null], ['2.0', null], [$this->current(), null],
            [$this->current(), $gemini->recipientFingerprint()], [$this->current(), $ollama->recipientFingerprint()],
            ['2.0', $gemini->recipientFingerprint()],
        ];
        foreach ($cases as [$version, $fp]) {
            foreach ([$gemini, $ollama, ConnectionStore::legacyGemini(false)] as $c) {
                $this->storedConsent($version, $fp);
                $this->assertSame($this->service()->isValidFor('alice', $c), $this->service()->state('alice', $c)['valid']);
            }
        }
    }

    // ---- grant -------------------------------------------------------------------------------

    public function testGrantStoresVersionRecipientSourceAndTime(): void {
        $this->row = null;
        $c = $this->gemini();
        $inserted = null;
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (UserTelos $e) use (&$inserted) {
            $inserted = $e;
            return $e;
        });
        $this->audit->expects($this->once())->method('logEvent')->with(
            'ai_consent_given', 'alice',
            $this->callback(fn (array $ctx) => $ctx['recipient_fp'] === substr($c->recipientFingerprint(), 0, 12)
                && $ctx['source'] === 'onboarding' && $ctx['version'] === '3.0'
                && !in_array($c->recipientFingerprint(), $ctx, true)),
        );

        $before = time();
        $this->service()->grant('alice', $c, $c->recipientFingerprint(), '3.0', 'onboarding');

        $this->assertInstanceOf(UserTelos::class, $inserted);
        $this->assertSame('alice', $inserted->getUserId());
        $this->assertSame('3.0', $inserted->getAiConsentVersion());
        $this->assertSame($c->recipientFingerprint(), $inserted->getAiConsentFingerprint());
        $this->assertSame('onboarding', $inserted->getAiConsentSource());
        $this->assertGreaterThanOrEqual($before, $inserted->getAiConsentAt());
    }

    public function testGrantUpdatesAnExistingRow(): void {
        $this->storedConsent('2.0', null, null);
        $c = $this->ollama('http://ollama.lan:11434');
        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service()->grant('alice', $c, $c->recipientFingerprint(), $this->current(), 'dialog');

        $this->assertTrue($this->service()->isValidFor('alice', $c));
    }

    public function testGrantWithAnotherFingerprintThrowsAndStoresNothing(): void {
        $this->row = null;
        $c = $this->gemini();
        $stale = $this->ollama('http://ollama.lan:11434')->recipientFingerprint();
        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->never())->method('update');

        try {
            $this->service()->grant('alice', $c, $stale, $this->current(), 'dialog');
            $this->fail('expected ConsentRecipientChangedException');
        } catch (ConsentRecipientChangedException $e) {
            // The 409 answer needs the recipient the server actually has.
            $this->assertSame($c->recipientFingerprint(), $e->currentFingerprint);
        }
    }

    public function testGrantRejectsAnOutdatedVersion(): void {
        $c = $this->gemini();
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->grant('alice', $c, $c->recipientFingerprint(), '2.0', 'dialog');
    }

    public function testGrantRejectsAnUnknownSource(): void {
        $c = $this->gemini();
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->grant('alice', $c, $c->recipientFingerprint(), $this->current(), 'admin-script');
    }

    public function testGrantRejectsADisabledConnection(): void {
        $c = ConnectionStore::legacyGemini(false);
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->grant('alice', $c, $c->recipientFingerprint(), $this->current(), 'dialog');
    }

    // ---- revoke / epoch ----------------------------------------------------------------------

    /**
     * Review B1: revoke must not be read-modify-write. NC entities write only dirty fields, so a
     * revoke that read a row without consent would not clear a grant committed in between.
     * One unconditional statement clears the consent and raises the epoch together.
     */
    public function testRevokeIsOneUnconditionalStatement(): void {
        $c = $this->gemini();
        $this->storedConsent($this->current(), $c->recipientFingerprint());
        $this->mapper->expects($this->never())->method('update');
        $this->mapper->expects($this->never())->method('incrementConsentEpoch');
        $this->mapper->expects($this->once())->method('revokeAiConsent')->with('alice', $this->isType('int'))
            ->willReturnCallback(function () {
                $this->storedConsent(null, null, null);
                return 1;
            });
        $this->audit->expects($this->once())->method('logEvent')->with('ai_consent_revoked', 'alice', $this->anything());

        $this->service()->revoke('alice');

        $this->assertFalse($this->service()->isValidFor('alice', $c));
    }

    public function testRevokeWithoutRowStoresNothingButIsAudited(): void {
        $this->row = null;
        $this->mapper->expects($this->never())->method('update');
        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->once())->method('revokeAiConsent')->with('alice', $this->isType('int'))->willReturn(0);
        $this->audit->expects($this->once())->method('logEvent')->with('ai_consent_revoked', 'alice', $this->anything());

        $this->service()->revoke('alice');
    }

    public function testEpochIsReadFreshFromTheDatabase(): void {
        $this->row = null;
        $this->assertSame(0, $this->service()->epoch('alice'));

        $service = $this->service();
        $this->storedConsent(null, null, null);
        $this->assertSame(4, $service->epoch('alice'));
        $this->row->setConsentEpoch(5);
        $this->assertSame(5, $service->epoch('alice'));
    }

    // ---- previous recipient (spec 3.4: previous_recipient_name) ------------------------------

    /** A grant as a real request makes it: the mapper keeps the row the service hands it. */
    private function grantAs(string $uid, LlmConnection $c): void {
        $keep = function (UserTelos $e): UserTelos {
            $this->row = $e;
            return $e;
        };
        $this->mapper->method('insert')->willReturnCallback($keep);
        $this->mapper->method('update')->willReturnCallback($keep);
        $this->service()->grant($uid, $c, $c->recipientFingerprint(), $this->current(), 'dialog');
    }

    /**
     * B6: after the admin changed the recipient, the dialog says "from {old} to {new}". The old
     * name is the recipient the user consented to, remembered when the consent was given.
     */
    public function testAfterAnAdminSwitchThePreviousRecipientIsTheOneTheUserConsentedTo(): void {
        $this->row = null;
        $this->grantAs('alice', $this->gemini());

        $ollama = $this->ollama('http://ollama.lan:11434');
        $this->assertSame('recipient_changed', $this->service()->state('alice', $ollama)['reason']);
        $this->assertSame('Google Gemini', $this->service()->previousRecipientName('alice', $ollama));
    }

    public function testThereIsNoPreviousRecipientUnlessTheRecipientChanged(): void {
        $this->row = null;
        $c = $this->gemini();
        $this->grantAs('alice', $c);

        $this->assertNull($this->service()->previousRecipientName('alice', $c), 'valid: nothing changed');

        $this->storedConsent('2.0', $c->recipientFingerprint());
        $this->assertNull($this->service()->previousRecipientName('alice', $this->ollama('http://ollama.lan:11434')),
            'an older consent text is "revised", not "recipient changed"');

        $this->row = null;
        $this->assertNull($this->service()->previousRecipientName('alice', $c), 'never consented');
    }

    public function testAnUnknownPreviousRecipientHasNoName(): void {
        $this->storedConsent($this->current(), str_repeat('a', 64));

        $ollama = $this->ollama('http://ollama.lan:11434');
        $this->assertSame('recipient_changed', $this->service()->state('alice', $ollama)['reason']);
        $this->assertNull($this->service()->previousRecipientName('alice', $ollama));
    }

    /** Data export (Art. 15/20): the readable name of the recipient a stored consent was given for. */
    public function testRecipientNameForReturnsTheRememberedNameOfAFingerprint(): void {
        $this->row = null;
        $c = $this->gemini();
        $this->grantAs('alice', $c);

        $service = $this->service();
        $this->assertSame('Google Gemini', $service->recipientNameFor($c->recipientFingerprint()));
        $this->assertNull($service->recipientNameFor(str_repeat('b', 64)), 'a recipient that is no longer remembered has no name');
        $this->assertNull($service->recipientNameFor(null));
        $this->assertNull($service->recipientNameFor(''));
    }

    /** The name list belongs to the recipients, not to the users: no user ids, and bounded. */
    public function testTheRememberedRecipientsHoldNoUserIdsAndStayBounded(): void {
        $this->row = null;
        $n = ConsentService::MAX_REMEMBERED_RECIPIENTS;
        for ($i = 0; $i < $n + 5; $i++) {
            $this->grantAs('alice', $this->ollama('http://ollama' . $i . '.lan:11434'));
        }

        $raw = $this->values->get('learning', ConsentService::RECIPIENTS_KEY);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('alice', $raw);
        $map = json_decode($raw, true);
        $this->assertIsArray($map);
        $this->assertCount($n, $map);
        $first = $this->ollama('http://ollama0.lan:11434');
        $last = $this->ollama('http://ollama' . ($n + 4) . '.lan:11434');
        $this->assertArrayNotHasKey($first->recipientFingerprint(), $map, 'the oldest entries go first');
        $this->assertSame('Ollama (ollama' . ($n + 4) . '.lan)', $map[$last->recipientFingerprint()] ?? null);
    }

    public function testARecipientAlreadyRememberedIsNotWrittenAgain(): void {
        $this->row = null;
        $this->grantAs('alice', $this->gemini());
        $writes = $this->values->setCalls;

        $this->grantAs('bob', $this->gemini());

        $this->assertSame($writes, $this->values->setCalls);
    }
}
