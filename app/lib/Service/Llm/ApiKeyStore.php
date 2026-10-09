<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * API keys, encrypted with ICrypto and bound to the connection fingerprint (spec 2.3): a key is
 * stored under `ai_key_<fingerprint prefix>`, so pointing the connection at another endpoint
 * finds no key and the admin has to enter one for it.
 *
 * The config key uses the first 32 hex characters of the fingerprint (128 bit): Nextcloud's
 * AppConfig rejects keys longer than 64 characters, and `ai_key_` + 64 hex would be 71.
 *
 * The 5.5 plaintext `gemini_api_key` is honoured for the legacy Gemini connection until it is
 * migrated, and it is only ever deleted after an encrypted copy was written and read back.
 * No method logs key material.
 */
class ApiKeyStore {
    public const APP = 'learning';
    public const KEY_PREFIX = 'ai_key_';
    public const FINGERPRINT_CHARS = 32;
    public const LEGACY_GEMINI_KEY = 'gemini_api_key';

    public function __construct(
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }

    public static function keyName(LlmConnection $c): string {
        return self::KEY_PREFIX . substr($c->connectionFingerprint(), 0, self::FINGERPRINT_CHARS);
    }

    public function get(LlmConnection $c): ?string {
        if ($c->provider === 'disabled') {
            return null;
        }
        $key = $this->readEncrypted(self::keyName($c));
        if ($key !== null) {
            return $key;
        }
        if ($this->isLegacyGemini($c)) {
            $plain = trim($this->appConfig->getValueString(self::APP, self::LEGACY_GEMINI_KEY, ''));
            return $plain !== '' ? $plain : null;
        }
        return null;
    }

    /**
     * @param array{action?: mixed, value?: mixed} $keyAction  action keep|replace|delete; value for replace
     */
    public function apply(LlmConnection $c, array $keyAction): void {
        $action = $keyAction['action'] ?? null;
        if ($action === 'keep') {
            return;
        }
        if ($c->provider === 'disabled') {
            throw new \InvalidArgumentException('A disabled connection has no API key');
        }
        $name = self::keyName($c);

        if ($action === 'delete') {
            $this->appConfig->deleteKey(self::APP, $name);
            if ($this->isLegacyGemini($c)) {
                // Otherwise get() would fall back to the plaintext and the delete would not take effect.
                $this->appConfig->deleteKey(self::APP, self::LEGACY_GEMINI_KEY);
            }
            return;
        }

        if ($action !== 'replace') {
            throw new \InvalidArgumentException('Unknown key action');
        }
        $value = is_string($keyAction['value'] ?? null) ? trim($keyAction['value']) : '';
        if ($value === '') {
            throw new \InvalidArgumentException('An API key must not be empty');
        }
        if (!$this->writeVerified($name, $value)) {
            throw new \RuntimeException('The API key could not be stored');
        }
        if ($this->isLegacyGemini($c)) {
            $this->appConfig->deleteKey(self::APP, self::LEGACY_GEMINI_KEY);
        }
    }

    /**
     * Moves the 5.5 plaintext Gemini key to its encrypted, fingerprint-bound slot. Idempotent.
     *
     * $afterKeyWrite runs only when this call wrote the encrypted copy, after it was read back and
     * before the plaintext is deleted (the lifecycle probe's PROBE_FAIL_AFTER=key_write point).
     * Whatever it throws reaches the caller; the next run finds both copies and answers 'already'.
     *
     * @param (\Closure(): void)|null $afterKeyWrite
     * @return string 'migrated'|'already'|'none'|'kept_plaintext'
     */
    public function migrateLegacyGeminiKey(?\Closure $afterKeyWrite = null): string {
        // Decide on what is stored, not on what this process happens to have cached.
        $this->appConfig->clearCache();
        $name = self::keyName(ConnectionStore::legacyGemini(false));
        $plain = trim($this->appConfig->getValueString(self::APP, self::LEGACY_GEMINI_KEY, ''));

        if ($this->appConfig->hasKey(self::APP, $name)) {
            if ($this->readEncrypted($name) !== null) {
                if ($this->appConfig->hasKey(self::APP, self::LEGACY_GEMINI_KEY)) {
                    $this->appConfig->deleteKey(self::APP, self::LEGACY_GEMINI_KEY);
                }
                return 'already';
            }
            if ($plain === '') {
                return 'none';
            }
            $this->logger->warning('Gemini key migration skipped: the encrypted key exists but cannot be decrypted; plaintext key kept', ['app' => self::APP]);
            return 'kept_plaintext';
        }

        if ($plain === '') {
            return 'none';
        }
        if (!$this->writeVerified($name, $plain)) {
            $this->logger->warning('Gemini key migration failed: encrypted copy could not be verified; plaintext key kept', ['app' => self::APP]);
            return 'kept_plaintext';
        }
        if ($afterKeyWrite !== null) {
            $afterKeyWrite();
        }
        $this->appConfig->deleteKey(self::APP, self::LEGACY_GEMINI_KEY);
        return 'migrated';
    }

    /**
     * Writes the encrypted value, drops the process cache and reads it back from storage. On any
     * mismatch or error the previous entry is restored (or the new one removed if there was none)
     * and false is returned: a failed replace never costs the admin a working key.
     */
    private function writeVerified(string $name, string $value): bool {
        try {
            $cipher = $this->crypto->encrypt($value);
        } catch (\Throwable) {
            return false; // nothing written yet
        }
        $previous = null;
        try {
            if ($this->appConfig->hasKey(self::APP, $name)) {
                $previous = $this->appConfig->getValueString(self::APP, $name, '');
            }
        } catch (\Throwable) {
            $previous = null; // unreadable already, nothing worth restoring
        }
        try {
            $this->appConfig->setValueString(self::APP, $name, $cipher, false, true);
            $this->appConfig->clearCache();
            if ($this->readEncrypted($name) === $value) {
                return true;
            }
        } catch (\Throwable) {
            // fall through: never leave a half-written key behind
        }
        try {
            if ($previous !== null && $previous !== '') {
                $this->appConfig->setValueString(self::APP, $name, $previous, false, true);
            } else {
                $this->appConfig->deleteKey(self::APP, $name);
            }
        } catch (\Throwable) {
            $this->logger->warning('A failed AI API key write could not be rolled back', ['app' => self::APP]);
        }
        return false;
    }

    private function readEncrypted(string $name): ?string {
        if (!$this->appConfig->hasKey(self::APP, $name)) {
            return null;
        }
        try {
            // Nextcloud decrypts sensitive values inside getValueString(); that layer throws too.
            $stored = $this->appConfig->getValueString(self::APP, $name, '');
            if ($stored === '') {
                return null;
            }
            $key = trim($this->crypto->decrypt($stored));
        } catch (\Throwable) {
            $this->logger->warning('A stored AI API key could not be decrypted', ['app' => self::APP]);
            return null;
        }
        return $key !== '' ? $key : null;
    }

    private function isLegacyGemini(LlmConnection $c): bool {
        return $c->provider === 'gemini'
            && $c->connectionFingerprint() === ConnectionStore::legacyGemini(false)->connectionFingerprint();
    }
}
