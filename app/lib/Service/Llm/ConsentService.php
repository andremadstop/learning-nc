<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditService;
use OCP\IAppConfig;

/**
 * Per-user AI consent, bound to the consent text version AND the recipient (spec 3.1–3.3).
 *
 *   isValidFor(uid, c) := c.enabled && stored.version == current && stored.fingerprint == c.recipientFingerprint()
 *
 * There is no exception for local processing: processingLocal is part of the recipient
 * fingerprint, it never makes a consent for another recipient valid.
 *
 * Revocation clears the consent and raises consent_epoch in one statement (at least as strong as
 * spec 2.4's "clear, then raise"); the gateway compares the epoch from begin() with a fresh read
 * right before sending.
 *
 * The readable name of a recipient is not part of a consent (spec 3.2). To say "changed from
 * {old} to {new}" (spec 3.4 previous_recipient_name), grant() remembers fingerprint => name of the
 * recipients consented to in app config: no user ids, the newest MAX_REMEMBERED_RECIPIENTS only.
 */
class ConsentService {
    public const SOURCES = ['dialog', 'onboarding'];
    private const APP = 'learning';
    public const RECIPIENTS_KEY = 'ai_consent_recipients';
    public const MAX_REMEMBERED_RECIPIENTS = 20;

    private ?string $currentVersionCache = null;

    public function __construct(
        private UserTelosMapper $mapper,
        private AuditService $auditService,
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * The version of the current consent text (`data/ai-consent.json`). Empty if the file is
     * unreadable, and then no consent is valid.
     */
    public function currentVersion(): string {
        if ($this->currentVersionCache === null) {
            $raw = @file_get_contents(__DIR__ . '/../../../data/ai-consent.json');
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $version = is_array($data) ? ($data['version'] ?? '') : '';
            $this->currentVersionCache = is_string($version) ? trim($version) : '';
        }
        return $this->currentVersionCache;
    }

    public function isValidFor(string $uid, LlmConnection $c): bool {
        return $this->state($uid, $c)['valid'];
    }

    /**
     * reason: why the user would have to consent (again) for this connection.
     * - none: valid, or AI is switched off (nothing to consent to)
     * - never: no consent, or one that never named a recipient
     * - version: given for an older consent text (wins over a changed recipient, so upgraded
     *   users see the "revised" header)
     * - recipient_changed: current text, but another recipient
     *
     * @return array{valid: bool, reason: 'none'|'never'|'version'|'recipient_changed', source: ?string}
     */
    public function state(string $uid, LlmConnection $c): array {
        $row = $this->mapper->findByUserIdOrNull($uid);
        $version = $row?->getAiConsentVersion();
        $fingerprint = $row?->getAiConsentFingerprint();
        $source = $row?->getAiConsentSource();

        if (!self::isActive($c)) {
            return ['valid' => false, 'reason' => 'none', 'source' => $source];
        }
        if ($version === null || $version === '') {
            return ['valid' => false, 'reason' => 'never', 'source' => $source];
        }
        $current = $this->currentVersion();
        if ($current === '' || !hash_equals($current, $version)) {
            return ['valid' => false, 'reason' => 'version', 'source' => $source];
        }
        if ($fingerprint === null || $fingerprint === '') {
            return ['valid' => false, 'reason' => 'never', 'source' => $source];
        }
        if (!hash_equals($c->recipientFingerprint(), $fingerprint)) {
            return ['valid' => false, 'reason' => 'recipient_changed', 'source' => $source];
        }
        return ['valid' => true, 'reason' => 'none', 'source' => $source];
    }

    /**
     * Stores a consent for the recipient of $c. The client sends the fingerprint it showed the
     * user; if the admin changed the recipient meanwhile, nothing is stored.
     *
     * @throws ConsentRecipientChangedException the claimed fingerprint is not the current recipient
     * @throws \InvalidArgumentException outdated version, unknown source or AI switched off
     */
    public function grant(string $uid, LlmConnection $c, string $claimedFingerprint, string $version, string $source): void {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown consent source');
        }
        $current = $this->currentVersion();
        if ($current === '' || !hash_equals($current, $version)) {
            throw new \InvalidArgumentException('Consent version is not current');
        }
        if (!self::isActive($c)) {
            throw new \InvalidArgumentException('AI is switched off; there is no recipient to consent to');
        }
        $recipient = $c->recipientFingerprint();
        if (!hash_equals($recipient, $claimedFingerprint)) {
            throw new ConsentRecipientChangedException($recipient);
        }

        // Before the consent is stored: if remembering the name fails, no consent was stored either.
        $this->rememberRecipient($recipient, (new LlmSession($c, null, LlmPurpose::AdminTest))->recipient()->name);

        $now = time();
        $row = $this->mapper->findByUserIdOrNull($uid);
        $isNew = $row === null;
        if ($row === null) {
            $row = new UserTelos();
            $row->setUserId($uid);
            $row->setCreatedAt($now);
        }
        $row->setAiConsentVersion($version);
        $row->setAiConsentFingerprint($recipient);
        $row->setAiConsentSource($source);
        $row->setAiConsentAt($now);
        $row->setUpdatedAt($now);
        if ($isNew) {
            $this->mapper->insert($row);
        } else {
            $this->mapper->update($row);
        }

        $this->auditService->logEvent('ai_consent_given', $uid, [
            'version' => $version,
            'source' => $source,
            'provider' => $c->provider,
            'recipient_fp' => substr($recipient, 0, 12),
        ]);
    }

    /**
     * Revokes the consent with one unconditional UPDATE (no read first): a grant of the same user
     * that commits meanwhile cannot survive a revocation that reports success.
     */
    public function revoke(string $uid): void {
        $this->mapper->revokeAiConsent($uid, time());
        $this->auditService->logEvent('ai_consent_revoked', $uid, []);
    }

    /**
     * Name of the recipient the user consented to, while the consent is for another recipient
     * than $c (reason recipient_changed). Null otherwise, or when the name is no longer known.
     */
    public function previousRecipientName(string $uid, LlmConnection $c): ?string {
        if ($this->state($uid, $c)['reason'] !== 'recipient_changed') {
            return null;
        }
        $fingerprint = $this->mapper->findByUserIdOrNull($uid)?->getAiConsentFingerprint();
        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }
        $name = $this->rememberedRecipients()[$fingerprint] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    private function rememberRecipient(string $fingerprint, string $name): void {
        $map = $this->rememberedRecipients();
        if (($map[$fingerprint] ?? null) === $name) {
            return;
        }
        unset($map[$fingerprint]);
        $map[$fingerprint] = $name;
        $map = array_slice($map, -self::MAX_REMEMBERED_RECIPIENTS, null, true);
        $this->appConfig->setValueString(self::APP, self::RECIPIENTS_KEY,
            json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> fingerprint => readable name, oldest first */
    private function rememberedRecipients(): array {
        $data = json_decode($this->appConfig->getValueString(self::APP, self::RECIPIENTS_KEY, ''), true);
        if (!is_array($data)) {
            return [];
        }
        $map = [];
        foreach ($data as $fingerprint => $name) {
            if (is_string($fingerprint) && is_string($name)) {
                $map[$fingerprint] = $name;
            }
        }
        return $map;
    }

    /** Read from the database on every call; the gateway relies on seeing a revocation at once. */
    public function epoch(string $uid): int {
        return $this->mapper->findByUserIdOrNull($uid)?->getConsentEpoch() ?? 0;
    }

    private static function isActive(LlmConnection $c): bool {
        return $c->enabled && $c->provider !== 'disabled';
    }
}
