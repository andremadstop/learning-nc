<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

/**
 * The operator of this Learning installation: provider in the imprint and controller in the privacy
 * statement. Entered by an admin (Settings -> Administration -> Learning), stored as app config.
 *
 * Until 5.8 these details were hard-coded in data/privacy-info.json, so every installation named the
 * app author's company as its operator (audit 2026-11). Nothing is shipped any more: without an entry
 * the pages show a neutral hint, and Learning does not take over the Nextcloud imprint/privacy links.
 *
 * "Configured" means name, address and a valid contact email are all present (isConfigured()). The
 * public pages, the theming link sync and the admin endpoint all use this one predicate.
 */
class OperatorInfo {
    /** Field => maximum length (characters). */
    public const FIELDS = [
        'name' => 200,
        'address' => 500,
        'register' => 200,
        'email' => 254,
        'content_responsible' => 200,
    ];
    public const REQUIRED = ['name', 'address', 'email'];
    private const KEY_PREFIX = 'operator_';

    public function __construct(private IConfig $config) {
    }

    /** @return array{name: string, address: string, register: string, email: string, content_responsible: string} */
    public function load(): array {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $values[$field] = $this->config->getAppValue('learning', self::KEY_PREFIX . $field, '');
        }
        /** @var array{name: string, address: string, register: string, email: string, content_responsible: string} $values */
        return $values;
    }

    public function isConfigured(): bool {
        $values = $this->load();
        return !self::isEmpty($values) && self::validate($values) === [];
    }

    /**
     * Only the operator fields, for the public imprint/privacy pages; null while no complete entry
     * exists.
     *
     * @return array{name: string, address: string, register: string, email: string, content_responsible: string}|null
     */
    public function publicView(): ?array {
        return $this->isConfigured() ? $this->load() : null;
    }

    /**
     * Stores already normalized and validated values; empty fields are removed. All fields empty
     * removes the operator.
     *
     * @param array<string, string> $values output of normalize(), validate() returned []
     */
    public function save(array $values): void {
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $values[$field] ?? '';
            if ($value === '') {
                $this->config->deleteAppValue('learning', self::KEY_PREFIX . $field);
            } else {
                $this->config->setAppValue('learning', self::KEY_PREFIX . $field, $value);
            }
        }
    }

    /**
     * Trims every field and strips control characters; the address keeps line breaks (at most one
     * empty line in a row). Unknown keys are dropped, non-strings become ''.
     *
     * @param array<string, mixed> $input
     * @return array{name: string, address: string, register: string, email: string, content_responsible: string}
     */
    public static function normalize(array $input): array {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $raw = $input[$field] ?? '';
            $value = is_string($raw) ? $raw : '';
            if ($field === 'address') {
                $value = str_replace(["\r\n", "\r"], "\n", $value);
                $value = (string)preg_replace('/[^\P{C}\n]/u', '', $value);
                $value = implode("\n", array_map('trim', explode("\n", $value)));
                $value = (string)preg_replace("/\n{3,}/", "\n\n", $value);
            } else {
                $value = (string)preg_replace('/\p{C}/u', '', $value);
            }
            $values[$field] = trim($value);
        }
        /** @var array{name: string, address: string, register: string, email: string, content_responsible: string} $values */
        return $values;
    }

    /**
     * Field => error code ('required', 'invalid', 'too_long'); [] when the values may be stored.
     * All fields empty is valid (it removes the operator).
     *
     * @param array<string, string> $values output of normalize()
     * @return array<string, string>
     */
    public static function validate(array $values): array {
        if (self::isEmpty($values)) {
            return [];
        }
        $errors = [];
        foreach (self::FIELDS as $field => $max) {
            $value = $values[$field] ?? '';
            if ($value === '') {
                if (in_array($field, self::REQUIRED, true)) {
                    $errors[$field] = 'required';
                }
                continue;
            }
            if (mb_strlen($value) > $max) {
                $errors[$field] = 'too_long';
                continue;
            }
            if ($field === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field] = 'invalid';
            }
        }
        return $errors;
    }

    /** @param array<string, string> $values */
    private static function isEmpty(array $values): bool {
        foreach (array_keys(self::FIELDS) as $field) {
            if (($values[$field] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }
}
