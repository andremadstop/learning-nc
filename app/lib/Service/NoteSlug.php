<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * File name slug of a pool's summary note in /Learning/Zusammenfassungen. NoteGeneratorService
 * writes the file under this name and LernplanService links to it, so both must use this one helper.
 *
 * A name of ASCII letters, digits and German umlauts keeps the slug it always had
 * ("VLAN Konfiguration" → "vlan-konfiguration", "Prüfung" → "pruefung"), so existing notes are
 * still found. Any other letter used to become '-', and every Cyrillic or Arabic pool name ended up
 * as "note.md", each summary overwriting the last (2026-11 audit #25). Such a name is now
 * transliterated where the server can, and always gets the pool id appended, so two pools never
 * share a file.
 */
final class NoteSlug {
    private const UMLAUTS = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];

    public static function forPool(string $name, int $poolId): string {
        $lower = str_replace(array_keys(self::UMLAUTS), array_values(self::UMLAUTS), mb_strtolower($name));

        if (preg_match('/[^\x00-\x7F]/', $lower) !== 1) {
            $slug = self::clean($lower);
            return $slug !== '' ? $slug : 'note';
        }

        $slug = self::clean(self::transliterate($lower));
        return ($slug !== '' ? $slug : 'pool') . '-' . $poolId;
    }

    private static function clean(string $value): string {
        $slug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($value)) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    /** Latin letters for the name where intl or iconv can provide them; '' when neither can. */
    private static function transliterate(string $value): string {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $result = $transliterator->transliterate($value);
                if (is_string($result)) {
                    return $result;
                }
            }
        }
        if (function_exists('iconv')) {
            $result = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($result)) {
                return $result;
            }
        }
        return '';
    }
}
