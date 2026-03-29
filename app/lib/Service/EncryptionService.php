<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\Security\ICrypto;

/**
 * EncryptionService -- wraps ICrypto with null handling and plaintext fallback.
 *
 * Used to encrypt sensitive fields (bio, telos_json) at rest.
 * Gracefully falls back to returning the raw string if decryption fails,
 * which handles pre-migration plaintext data.
 */
class EncryptionService {
    private ICrypto $crypto;

    public function __construct(ICrypto $crypto) {
        $this->crypto = $crypto;
    }

    /**
     * Encrypt a plaintext string. Returns null/empty as-is.
     */
    public function encrypt(?string $plaintext): ?string {
        if ($plaintext === null) {
            return null;
        }
        if ($plaintext === '') {
            return '';
        }
        return $this->crypto->encrypt($plaintext);
    }

    /**
     * Decrypt a ciphertext string. Returns null/empty as-is.
     * Falls back to returning the raw string if decryption fails (plaintext fallback).
     */
    public function decrypt(?string $ciphertext): ?string {
        if ($ciphertext === null) {
            return null;
        }
        if ($ciphertext === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($ciphertext);
        } catch (\Exception $e) {
            // Plaintext fallback: if the value was never encrypted, return as-is
            return $ciphertext;
        }
    }
}
