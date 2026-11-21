<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Migration\Version011600Date20261121140000;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * French and Ukrainian translations (5.18.0) are stored — also on a PostgreSQL instance upgraded
 * through 3.2, whose translation tables carry Version003200's CHECK constraint (de/en/ru/ar) —
 * after Version007900's rename on learning_qst_/learning_ans_translations, under its old name.
 *
 * A fresh install (what this suite runs on) never got that constraint, so the test puts it back
 * as an upgraded instance has it, runs Version011600's postSchemaChange() and stores fr/uk.
 * Without the step the PostgreSQL insert fails with a check violation. On MariaDB the step does
 * nothing (the old constraint never applied there) and the translations are stored as well.
 */
final class TranslationLanguagesTest extends TestCase {
    private const USER = 'translation-langs-it';
    private const OLD_LANGS = "ARRAY['de'::character varying, 'en'::character varying, 'ru'::character varying, 'ar'::character varying]";

    /** Table the mappers use → the name the constraint kept through Version007900's rename. */
    private const UPGRADED = [
        'learning_qst_translations' => 'learning_q_translations_lang_check',
        'learning_ans_translations' => 'learning_a_translations_lang_check',
    ];

    private IDBConnection $db;
    private string $prefix;
    private ?int $poolId = null;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
    }

    protected function tearDown(): void {
        if ($this->poolId !== null) {
            try {
                Server::get(PoolService::class)->delete($this->poolId, self::USER);
            } catch (\Throwable $e) {
                // best effort: a failed cleanup must not hide the assertion that failed
            }
        }
        // Back to the state of a fresh install: no constraint.
        if ($this->isPostgres()) {
            foreach (self::UPGRADED as $table => $constraint) {
                $this->db->executeStatement("ALTER TABLE {$this->prefix}{$table} DROP CONSTRAINT IF EXISTS {$this->prefix}{$constraint}");
            }
        }
    }

    public function testFrenchAndUkrainianAreStoredAfterTheUpgradeStep(): void {
        if ($this->isPostgres()) {
            // As Version003200 + Version007900 left it on an instance upgraded through 3.2.
            foreach (self::UPGRADED as $table => $constraint) {
                $this->db->executeStatement("ALTER TABLE {$this->prefix}{$table} DROP CONSTRAINT IF EXISTS {$this->prefix}{$constraint}");
                $this->db->executeStatement("ALTER TABLE {$this->prefix}{$table} ADD CONSTRAINT {$this->prefix}{$constraint} CHECK ((lang)::text = ANY ((" . self::OLD_LANGS . ")::text[]))");
            }
        }

        (new Version011600Date20261121140000($this->db, Server::get(IConfig::class)))
            ->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

        $pool = Server::get(PoolService::class)->create('Translation languages', null, self::USER);
        $this->poolId = (int)$pool->getId();
        $question = Server::get(QuestionService::class)->create($this->poolId, self::USER, 'Which port does SSH use?', null, 'easy', [
            ['text' => '22', 'is_correct' => true],
            ['text' => '23', 'is_correct' => false],
        ], 'single');
        $translations = Server::get(TranslationService::class);
        $translations->setQuestionTranslation((int)$question['id'], 'fr', 'Quel port utilise SSH ?');
        $translations->setQuestionTranslation((int)$question['id'], 'uk', 'Який порт використовує SSH?');
        $translations->setAnswerTranslation((int)$question['answers'][0]['id'], 'fr', '22 (fr)');
        $translations->setAnswerTranslation((int)$question['answers'][0]['id'], 'uk', '22 (uk)');

        $stored = [];
        foreach (Server::get(QuestionTranslationMapper::class)->findByQuestions([(int)$question['id']]) as $t) {
            $stored[(string)$t->getLang()] = (string)$t->getText();
        }
        ksort($stored);
        $this->assertSame(['fr' => 'Quel port utilise SSH ?', 'uk' => 'Який порт використовує SSH?'], $stored);
        $answers = [];
        foreach (Server::get(AnswerTranslationMapper::class)->findByAnswers([(int)$question['answers'][0]['id']]) as $t) {
            $answers[(string)$t->getLang()] = (string)$t->getText();
        }
        $this->assertSame(['fr' => '22 (fr)', 'uk' => '22 (uk)'], $answers);

        if ($this->isPostgres()) {
            // The guard is replaced, not dropped: a language outside the list is still refused.
            $this->assertTrue($this->hasLangCheck('learning_qst_translations'), 'question translations keep their guard');
            $this->assertTrue($this->hasLangCheck('learning_ans_translations'), 'answer translations keep their guard');
            $refused = false;
            try {
                $this->db->executeStatement(
                    "INSERT INTO {$this->prefix}learning_ans_translations (answer_id, lang, text, created_at) VALUES (?, 'es', 'x', 0)",
                    [(int)$question['answers'][1]['id']]
                );
            } catch (\Throwable $e) {
                $refused = true;
            }
            $this->assertTrue($refused, 'the widened constraint still refuses a language outside TranslationService::ALLOWED_LANGS');
        }
    }

    public function testTheStepDoesNothingWithoutTheOldConstraint(): void {
        (new Version011600Date20261121140000($this->db, Server::get(IConfig::class)))
            ->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

        if ($this->isPostgres()) {
            $this->assertFalse($this->hasLangCheck('learning_qst_translations'), 'a fresh install gets no constraint');
            $this->assertFalse($this->hasLangCheck('learning_ans_translations'), 'a fresh install gets no constraint');
        } else {
            $this->addToAssertionCount(1);
        }
    }

    private function isPostgres(): bool {
        return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
    }

    private function hasLangCheck(string $table): bool {
        $result = $this->db->executeQuery(
            "SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.contype = 'c' AND pg_get_constraintdef(c.oid) LIKE '%lang%'",
            [$this->prefix . $table]
        );
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }
}
