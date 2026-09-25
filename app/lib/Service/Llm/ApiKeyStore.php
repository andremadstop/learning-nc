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
     * @return string 'migrated'|'already'|'none'|'kept_plaintext'
     */
    public function migrateLegacyGeminiKey(): string {
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
        $this->appConfig->deleteKey(self::APP, self::LEGACY_GEMINI_KEY);
        return 'migrated';
    }

    /**
     * Writes the encrypted value, reads it back and decrypts it. On any mismatch or error the
     * written entry is removed again and false is returned.
     */
    private function writeVerified(string $name, string $value): bool {
        try {
            $this->appConfig->setValueString(self::APP, $name, $this->crypto->encrypt($value), false, true);
            if ($this->readEncrypted($name) === $value) {
                return true;
            }
        } catch (\Throwable) {
            // fall through: never leave a half-written key behind
        }
        $this->appConfig->deleteKey(self::APP, $name);
        return false;
    }

    private function readEncrypted(string $name): ?string {
        if (!$this->appConfig->hasKey(self::APP, $name)) {
            return null;
        }
        $stored = $this->appConfig->getValueString(self::APP, $name, '');
        if ($stored === '') {
            return null;
        }
        try {
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
