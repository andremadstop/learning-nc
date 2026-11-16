<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #43: how a course's certificates look. Pure helpers, no I/O.
 *
 * A layout is presentation only. Everything a placeholder stands for ({student.name},
 * {course.title}, {course.elements}, {score}, {exam.date}, {valid_until}, {issuer}) is read from
 * the signed credential when the card is drawn, so the credential, its verification and the public
 * verify page stay exactly as they were.
 *
 * Stored shape (learning_courses.cert_layout, snapshot in learning_certificates.layout_json):
 *   intro          introduction text with placeholders; '' = the standard "Awarded to: <name>" line
 *   show           element switches; QR code and verification link are not among them on purpose:
 *                  without them nobody can check the paper, so they are always printed.
 *                  show.issuer (GitHub #77) only hides the name beside the logo on the card: the
 *                  signed credential and the public verify page always carry the issuer name
 *   issuer_name    organisation name for the certificate (GitHub #77), '' = the fallback chain of
 *                  CertIssuerName (operator details, then the Theming name). Signed into issuer.name
 *                  of new certificates; the card shows the name from the credential, not this field
 *   signer_name    name of the person who signs, '' = none
 *   signer_role    their function, '' = none
 *   stamp_sha      sha256 of the stamp PNG in learning_cert_assets, null = none
 *   signature_sha  sha256 of the signature PNG, null = none
 *   logo_sha       sha256 of the course's logo from the logo library (GitHub #78), copied into
 *                  learning_cert_assets like the stamp; null = the Theming logo, as before
 *
 * The standard layout (no text, every element shown, no signer, no images) is never stored: a
 * course with it keeps cert_layout NULL and its certificates keep layout_json NULL, so they render
 * exactly the card every certificate had before 5.13.0.
 */
final class CertLayout {
    public const INTRO_MAX = 2000;
    public const SIGNER_MAX = 120;
    /** GitHub #77: as long as the operator's legal name (OperatorInfo::FIELDS['name']). */
    public const ISSUER_NAME_MAX = 200;

    /** Element switches, all on in the standard layout. */
    public const SHOW_KEYS = ['logo', 'issuer', 'score', 'threshold', 'elements', 'validity'];

    public const IMAGE_KINDS = ['stamp', 'signature'];

    /**
     * @return array{intro: string, show: array<string, bool>, issuer_name: string, signer_name: string, signer_role: string, stamp_sha: ?string, signature_sha: ?string, logo_sha: ?string}
     */
    public static function defaults(): array {
        return [
            'intro' => '',
            'show' => array_fill_keys(self::SHOW_KEYS, true),
            'issuer_name' => '',
            'signer_name' => '',
            'signer_role' => '',
            'stamp_sha' => null,
            'signature_sha' => null,
            'logo_sha' => null,
        ];
    }

    /**
     * Decode a stored layout. NULL, invalid JSON or a non-object give the standard layout; unknown
     * keys are dropped, missing ones take their standard value.
     *
     * @return array{intro: string, show: array<string, bool>, issuer_name: string, signer_name: string, signer_role: string, stamp_sha: ?string, signature_sha: ?string, logo_sha: ?string}
     */
    public static function decode(?string $json): array {
        $layout = self::defaults();
        if ($json === null || $json === '') {
            return $layout;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $layout;
        }
        $layout['intro'] = self::cleanText($data['intro'] ?? '', self::INTRO_MAX, true);
        if (is_array($data['show'] ?? null)) {
            foreach (self::SHOW_KEYS as $key) {
                if (array_key_exists($key, $data['show'])) {
                    $layout['show'][$key] = (bool)$data['show'][$key];
                }
            }
        }
        $layout['issuer_name'] = self::cleanText($data['issuer_name'] ?? '', self::ISSUER_NAME_MAX, false);
        $layout['signer_name'] = self::cleanText($data['signer_name'] ?? '', self::SIGNER_MAX, false);
        $layout['signer_role'] = self::cleanText($data['signer_role'] ?? '', self::SIGNER_MAX, false);
        foreach (self::IMAGE_KINDS as $kind) {
            $layout[$kind . '_sha'] = self::cleanSha($data[$kind . '_sha'] ?? null);
        }
        $layout['logo_sha'] = self::cleanSha($data['logo_sha'] ?? null);
        return $layout;
    }

    /**
     * Apply what the course settings sent (intro, show, issuer_name, signer_name, signer_role) to the current
     * layout. The images are not settable here: they only change through the image endpoint, which
     * checks the file, and neither is the logo (GitHub #78, its own endpoint checks the library).
     * Keys that are not sent stay as they are.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current a decoded layout
     * @return array{intro: string, show: array<string, bool>, issuer_name: string, signer_name: string, signer_role: string, stamp_sha: ?string, signature_sha: ?string, logo_sha: ?string}
     * @throws \InvalidArgumentException when a value has the wrong type or is too long
     */
    public static function apply(array $input, array $current): array {
        $layout = self::decode((string)json_encode($current));
        if (array_key_exists('intro', $input)) {
            $layout['intro'] = self::requireText($input['intro'], self::INTRO_MAX, true, 'intro');
        }
        if (array_key_exists('show', $input)) {
            if (!is_array($input['show'])) {
                throw new \InvalidArgumentException('show must be an object');
            }
            foreach (self::SHOW_KEYS as $key) {
                if (array_key_exists($key, $input['show'])) {
                    if (!is_bool($input['show'][$key])) {
                        throw new \InvalidArgumentException('show.' . $key . ' must be true or false');
                    }
                    $layout['show'][$key] = $input['show'][$key];
                }
            }
        }
        if (array_key_exists('issuer_name', $input)) {
            $layout['issuer_name'] = self::requireText($input['issuer_name'], self::ISSUER_NAME_MAX, false, 'issuer_name');
        }
        foreach (['signer_name', 'signer_role'] as $key) {
            if (array_key_exists($key, $input)) {
                $layout[$key] = self::requireText($input[$key], self::SIGNER_MAX, false, $key);
            }
        }
        return $layout;
    }

    /** True for the standard layout, which is stored as NULL. */
    public static function isDefault(array $layout): bool {
        return self::encode($layout) === null;
    }

    /**
     * The value to store: NULL for the standard layout, otherwise the normalized JSON.
     *
     * @param array<string, mixed> $layout
     */
    public static function encode(array $layout): ?string {
        $normalized = self::decode((string)json_encode($layout));
        if ($normalized === self::defaults()) {
            return null;
        }
        return (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * What a certificate issued now stores: the course's layout of this moment, NULL for the
     * standard one.
     */
    public static function snapshot(?string $courseLayoutJson): ?string {
        return self::encode(self::decode($courseLayoutJson));
    }

    /**
     * The layout for the course settings (no image bytes, only whether there is one).
     *
     * @return array{intro: string, show: array<string, bool>, issuer_name: string, signer_name: string, signer_role: string, has_stamp: bool, has_signature: bool, logo_id: ?string}
     */
    public static function forSettings(?string $json): array {
        $layout = self::decode($json);
        return [
            'intro' => $layout['intro'],
            'show' => $layout['show'],
            'issuer_name' => $layout['issuer_name'],
            'signer_name' => $layout['signer_name'],
            'signer_role' => $layout['signer_role'],
            'has_stamp' => $layout['stamp_sha'] !== null,
            'has_signature' => $layout['signature_sha'] !== null,
            // GitHub #78: the library logo the course uses (its id is its sha256), null = Theming
            'logo_id' => $layout['logo_sha'],
        ];
    }

    /**
     * Strip control characters (line breaks stay in multi-line text), unify line endings, trim and
     * cut to $max characters. Never throws: used for stored data.
     */
    private static function cleanText(mixed $value, int $max, bool $multiline): string {
        if (!is_string($value)) {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $value);
        $text = str_replace("\t", ' ', $text);
        $pattern = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $text = (string)preg_replace($pattern, $multiline ? '' : ' ', $text);
        $text = trim($text);
        if (mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, $max));
        }
        return $text;
    }

    private static function requireText(mixed $value, int $max, bool $multiline, string $key): string {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($key . ' must be text');
        }
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($key . ' must be valid UTF-8');
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        if (mb_strlen(trim($normalized)) > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be at most %d characters', $key, $max));
        }
        return self::cleanText($value, $max, $multiline);
    }

    private static function cleanSha(mixed $value): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }
}
