<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CertLayout;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #43: the stored certificate layout. The standard layout is NULL (so every course and
 * certificate without one stays exactly as before), input is validated before anything is stored,
 * control characters never reach the card, and the images are not settable through the text form.
 */
class CertLayoutTest extends TestCase {
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testNullAndBrokenJsonAreTheStandardLayout(): void {
        $this->assertSame(CertLayout::defaults(), CertLayout::decode(null));
        $this->assertSame(CertLayout::defaults(), CertLayout::decode(''));
        $this->assertSame(CertLayout::defaults(), CertLayout::decode('{not json'));
        $this->assertSame(CertLayout::defaults(), CertLayout::decode('"a string"'));
    }

    public function testStandardLayoutIsStoredAsNull(): void {
        $this->assertNull(CertLayout::encode(CertLayout::defaults()));
        $this->assertNull(CertLayout::snapshot(null));
        $this->assertTrue(CertLayout::isDefault(CertLayout::defaults()));
        // Saving the form untouched (empty text, everything on) must not create a layout.
        $applied = CertLayout::apply([
            'intro' => '   ',
            'show' => array_fill_keys(CertLayout::SHOW_KEYS, true),
            'signer_name' => '',
            'signer_role' => null,
        ], CertLayout::defaults());
        $this->assertNull(CertLayout::encode($applied));
    }

    public function testApplyKeepsWhatIsNotSent(): void {
        $current = CertLayout::defaults();
        $current['intro'] = 'Hiermit wird bestätigt, dass {student.name}';
        $current['stamp_sha'] = self::SHA;

        $layout = CertLayout::apply(['show' => ['logo' => false]], $current);

        $this->assertSame('Hiermit wird bestätigt, dass {student.name}', $layout['intro']);
        $this->assertFalse($layout['show']['logo']);
        $this->assertTrue($layout['show']['score']);
        $this->assertSame(self::SHA, $layout['stamp_sha']);
    }

    public function testImagesCannotBeSetThroughTheForm(): void {
        $layout = CertLayout::apply(['stamp_sha' => self::SHA, 'signature_sha' => self::SHA], CertLayout::defaults());

        $this->assertNull($layout['stamp_sha']);
        $this->assertNull($layout['signature_sha']);
    }

    public function testControlCharactersAreStrippedAndLineBreaksKept(): void {
        $layout = CertLayout::apply([
            'intro' => "Zeile 1\r\nZeile\x07 2\x00\n{course.elements}",
            'signer_name' => "Dr.\nMaria\tMuster",
        ], CertLayout::defaults());

        $this->assertSame("Zeile 1\nZeile 2\n{course.elements}", $layout['intro']);
        $this->assertSame('Dr. Maria Muster', $layout['signer_name']);
    }

    public function testMarkupIsStoredAsPlainText(): void {
        // Escaping is the renderer's job (text nodes only); the store must not mangle the text.
        $layout = CertLayout::apply(['intro' => '<b>{student.name}</b> & Co'], CertLayout::defaults());

        $this->assertSame('<b>{student.name}</b> & Co', $layout['intro']);
    }

    public function testTooLongTextIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        CertLayout::apply(['intro' => str_repeat('ä', CertLayout::INTRO_MAX + 1)], CertLayout::defaults());
    }

    public function testMaximumLengthCountsCharactersNotBytes(): void {
        $layout = CertLayout::apply(['intro' => str_repeat('ä', CertLayout::INTRO_MAX)], CertLayout::defaults());

        $this->assertSame(CertLayout::INTRO_MAX, mb_strlen($layout['intro']));
    }

    public function testTooLongSignerIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        CertLayout::apply(['signer_role' => str_repeat('x', CertLayout::SIGNER_MAX + 1)], CertLayout::defaults());
    }

    public function testNonBooleanSwitchIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        CertLayout::apply(['show' => ['score' => 'no']], CertLayout::defaults());
    }

    public function testNonTextIntroIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        CertLayout::apply(['intro' => ['x']], CertLayout::defaults());
    }

    public function testUnknownKeysAndInvalidShaAreDroppedOnDecode(): void {
        $layout = CertLayout::decode((string)json_encode([
            'intro' => 'x',
            'show' => ['logo' => false, 'qr' => false],
            'stamp_sha' => '../../etc/passwd',
            'evil' => 1,
        ]));

        $this->assertSame(CertLayout::SHOW_KEYS, array_keys($layout['show']));
        $this->assertFalse($layout['show']['logo']);
        $this->assertNull($layout['stamp_sha']);
        $this->assertArrayNotHasKey('evil', $layout);
    }

    public function testSnapshotFreezesTheCourseLayout(): void {
        $layout = CertLayout::defaults();
        $layout['signer_name'] = 'Maria Muster';
        $layout['signature_sha'] = self::SHA;
        $json = CertLayout::encode($layout);

        $snapshot = CertLayout::snapshot($json);

        $this->assertNotNull($snapshot);
        $this->assertSame($layout, CertLayout::decode($snapshot));
    }

    public function testSettingsViewHasNoImageReferences(): void {
        $layout = CertLayout::defaults();
        $layout['stamp_sha'] = self::SHA;

        $view = CertLayout::forSettings(CertLayout::encode($layout));

        $this->assertTrue($view['has_stamp']);
        $this->assertFalse($view['has_signature']);
        $this->assertArrayNotHasKey('stamp_sha', $view);
    }
}
