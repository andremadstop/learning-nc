<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use PHPUnit\Framework\TestCase;

/**
 * LeakageAuditTest — CERT-03 / Rule-18 phase-close gate (155-07).
 *
 * Automated proof that the issuer signing secret (learning_cert_keys.secret_key_enc) cannot escape
 * the instance through ANY export / snapshot / app-package / serialize surface. Mirrors the enumerated
 * sign-off in .planning/phases/155-certificate-artifact-issuer/155-LEAKAGE-AUDIT.md (kept in lockstep).
 *
 * Two layers:
 *   (a) structural  — CertKey::jsonSerialize() never emits secret_key_enc nor any 'secret' substring;
 *   (b) source-grep — the enumerated export/mobility/archive/command surfaces never reference the
 *                     cert_keys table, the CertKey entity, or the secret_key_enc column;
 *   (c) hygiene     — KeyService + IssuanceService sodium_memzero the plaintext secret and never log it.
 */
class LeakageAuditTest extends TestCase {

    /** tests/Unit/Service -> app, then into lib/ */
    private function libPath(string $relative): string {
        return dirname(__DIR__, 3) . '/lib/' . $relative;
    }

    /**
     * The export / snapshot / command surfaces enumerated in 155-LEAKAGE-AUDIT.md (surfaces #1-#4).
     * NONE may reference the issuer secret column, the cert_keys table, or the CertKey entity.
     *
     * @return array<string, string> surface-name => absolute source path
     */
    private function exportSurfaces(): array {
        return [
            'DataExportService'     => $this->libPath('Service/DataExportService.php'),
            'DataMobilityService'   => $this->libPath('Service/DataMobilityService.php'),
            'CourseArchiveService'  => $this->libPath('Service/CourseArchiveService.php'),
            'ExportCourseCommand'   => $this->libPath('Command/ExportCourseCommand.php'),
            'ExportPoolCommand'     => $this->libPath('Command/ExportPoolCommand.php'),
            'ImportVaultCommand'    => $this->libPath('Command/ImportVaultCommand.php'),
            'ImportPoolJsonCommand' => $this->libPath('Command/ImportPoolJsonCommand.php'),
        ];
    }

    /** (a) The signing key's serialized form never carries the secret (surface #7). */
    public function testJsonSerializeOmitsSecret(): void {
        $key = new CertKey();
        $key->setKeyId('pub-key-id-fragment');
        $key->setPublicKeyB64u('PUBLIC_X_VALUE');
        $key->setSecretKeyEnc('SUPER_SECRET_CIPHERTEXT_DO_NOT_LEAK');
        $key->setStatus('active');
        $key->setCreatedAt(1234567890);

        $json = $key->jsonSerialize();

        // The secret column is structurally absent — not a key, not present as a value.
        $this->assertArrayNotHasKey('secret_key_enc', $json, 'secret_key_enc must never be serialized');
        $encoded = (string) json_encode($json);
        $this->assertStringNotContainsString('secret', $encoded, 'no "secret" substring may appear');
        $this->assertStringNotContainsString('SUPER_SECRET_CIPHERTEXT_DO_NOT_LEAK', $encoded, 'ciphertext must not leak');

        // Sanity: it DOES carry the public material (so the omission is targeted, not a broken serializer).
        $this->assertSame('pub-key-id-fragment', $json['key_id']);
        $this->assertSame('PUBLIC_X_VALUE', $json['public_key_b64u']);
        $this->assertSame('active', $json['status']);
    }

    /** (b) No export / snapshot / command surface references the issuer secret (surfaces #1-#4). */
    public function testNoExportSurfaceReferencesIssuerSecret(): void {
        foreach ($this->exportSurfaces() as $name => $path) {
            $this->assertFileExists($path, "$name source file is missing — audit surface drifted");
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString('secret_key_enc', $src, "$name must not reference the secret column");
            $this->assertStringNotContainsString('cert_keys', $src, "$name must not reference the cert_keys table");
            $this->assertStringNotContainsString('CertKey', $src, "$name must not reference the CertKey entity");
        }
    }

    /** (c) The plaintext secret is zeroed after use and never logged (surface #10). */
    public function testSecretIsZeroedNotLogged(): void {
        $keyService = (string) file_get_contents($this->libPath('Service/KeyService.php'));
        $issuance = (string) file_get_contents($this->libPath('Service/IssuanceService.php'));

        // Plaintext copies are wiped from memory after use.
        $this->assertStringContainsString('sodium_memzero', $keyService, 'KeyService must zero plaintext secrets');
        $this->assertStringContainsString('sodium_memzero', $issuance, 'IssuanceService must zero the secret after sign()');

        // The raw decrypted secret is never written to a log line.
        $this->assertStringNotContainsString('logger->info($secret', $keyService);
        $this->assertStringNotContainsString('logger->debug($secret', $keyService);
        $this->assertStringNotContainsString('logger->info($secret', $issuance);
    }
}
