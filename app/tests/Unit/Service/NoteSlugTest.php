<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\NoteSlug;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit #25: every pool name without Latin letters became "note.md", so the summary of
 * 'Безпека' overwrote the one of 'Мережі', and the weekly plan linked all of them to the same note.
 */
class NoteSlugTest extends TestCase {
    public function testCyrillicPoolNamesGetDistinctFiles(): void {
        $networks = NoteSlug::forPool('Мережі', 11);
        $security = NoteSlug::forPool('Безпека', 12);

        $this->assertNotSame($networks, $security);
        $this->assertNotSame('note', $networks);
        $this->assertNotSame('note', $security);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+-11$/', $networks);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+-12$/', $security);
    }

    public function testArabicNameWithTheSameLettersInTwoPoolsStillDiffers(): void {
        $this->assertNotSame(NoteSlug::forPool('الشبكات', 3), NoteSlug::forPool('الشبكات', 4));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', NoteSlug::forPool('الشبكات', 3));
    }

    public function testLatinAndUmlautNamesKeepTheirExistingSlug(): void {
        $this->assertSame('vlan-konfiguration', NoteSlug::forPool('VLAN Konfiguration', 5));
        $this->assertSame('pruefung-grosse-uebung', NoteSlug::forPool('Prüfung: Große Übung', 5));
        $this->assertSame('note', NoteSlug::forPool('!!!', 5));
    }

    public function testOtherLatinAccentsNoLongerCollideWithThePlainSpelling(): void {
        $this->assertNotSame(NoteSlug::forPool('Cafe', 1), NoteSlug::forPool('Café', 2));
        $this->assertStringEndsWith('-2', NoteSlug::forPool('Café', 2));
    }
}
