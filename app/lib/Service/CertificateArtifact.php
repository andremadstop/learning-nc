<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The one place that turns a stored certificate (its compact VC-JWT) into the downloadable file.
 *
 * The learner's own download (CertificateController::download) and the instructor's copies from the
 * compliance report (CourseCertificateController, GitHub #42) both call this, so an instructor keeps
 * exactly the file the learner gets — byte for byte, same name, same media type.
 *
 * DEFAULT (CERT-09): an Open Badges 3.0 JSON-LD EnvelopedVerifiableCredential wrapping the JWT,
 * application/ld+json, certificate-<vid>.json. 'jwt' → the raw compact JWT, application/vc+jwt, .jwt.
 */
final class CertificateArtifact {
    public const FORMAT_JSONLD = 'jsonld';
    public const FORMAT_JWT = 'jwt';

    private function __construct() {} // static-only class

    /** Any value other than 'jwt' selects the default JSON-LD artifact. */
    public static function normalizeFormat(string $format): string {
        return $format === self::FORMAT_JWT ? self::FORMAT_JWT : self::FORMAT_JSONLD;
    }

    public static function content(string $jwt, string $format): string {
        if (self::normalizeFormat($format) === self::FORMAT_JWT) {
            return $jwt;
        }
        $envelope = [
            '@context' => ['https://www.w3.org/ns/credentials/v2'],
            'type' => 'EnvelopedVerifiableCredential',
            'id' => 'data:application/vc+jwt,' . $jwt,
        ];
        return (string)json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }

    public static function fileName(string $verificationId, string $format): string {
        return 'certificate-' . $verificationId
            . (self::normalizeFormat($format) === self::FORMAT_JWT ? '.jwt' : '.json');
    }

    public static function mimeType(string $format): string {
        return self::normalizeFormat($format) === self::FORMAT_JWT ? 'application/vc+jwt' : 'application/ld+json';
    }
}
