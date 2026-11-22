<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Migration;

use OCA\Learning\Migration\Version011600Date20261121140000;
use OCA\Learning\Service\TranslationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 5.18.0 stopped on PostgreSQL during `occ app:update` / the app management update with
 * "Cannot access private constant TranslationService::ALLOWED_LANGS": the update runs the new
 * migrations in the process that booted with the installed version, where (on PHP 8.2/8.3) the
 * console commands had already loaded 5.17.0's TranslationService. A migration that reads an app
 * class reads whatever version of it that process holds. Migrations therefore reference nothing
 * of the app outside OCA\Learning\Migration; scripts/inprocess-update-probe.sh exercises the real
 * path in a container.
 */
class MigrationAppClassesTest extends TestCase {
    /** @return array<string, array{string}> */
    public static function migrationFiles(): array {
        $files = glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: [];
        $out = [];
        foreach ($files as $file) {
            $out[basename($file)] = [$file];
        }
        return $out;
    }

    #[DataProvider('migrationFiles')]
    public function testMigrationReferencesNoAppClassOutsideMigration(string $file): void {
        $code = (string)file_get_contents($file);
        // Strip comments: the class comment of Version011600 explains the incident by name.
        $tokens = token_get_all($code);
        $stripped = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }
        // Single or doubled backslashes (a class name in a string literal), and group use
        // (`use OCA\Learning\{Service\A, Db\B}`), all count.
        preg_match_all('/OCA\\\\+Learning\\\\+[A-Za-z_{][A-Za-z_\\\\]*/', $stripped, $m);
        $refs = array_values(array_unique(array_filter(
            array_map(static fn (string $ref): string => (string)preg_replace('/\\\\+/', '\\', $ref), $m[0]),
            static fn (string $ref): bool => $ref !== 'OCA\\Learning\\Migration' && !str_starts_with($ref, 'OCA\\Learning\\Migration\\')
        )));
        $this->assertSame([], $refs, basename($file) . ' reads app classes that may be loaded from the previous version during an update');
    }

    public function testFixtureSeesTheMigrations(): void {
        $this->assertGreaterThan(50, count(self::migrationFiles()));
        $this->assertArrayHasKey('Version011600Date20261121140000.php', self::migrationFiles());
    }

    public function testTranslationLanguageCopyMatchesTheService(): void {
        // When ALLOWED_LANGS grows, an upgraded PostgreSQL instance needs a NEW migration that widens
        // the check again — this one already ran there. Do not edit the copy to make this pass.
        $this->assertSame(
            TranslationService::ALLOWED_LANGS,
            Version011600Date20261121140000::LANGS,
            'TranslationService::ALLOWED_LANGS changed: add a migration that widens the lang check'
        );
    }
}
