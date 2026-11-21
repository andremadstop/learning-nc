<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Support;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionTranslation;

/**
 * The columns a pool export (learning-pool/2) carries and the ones it leaves out, per entity —
 * shared by the round-trip tests against the entities (tests/Unit/Service/PoolDocumentRoundTripTest)
 * and against a real database (tests/Integration/PoolJsonRoundTripTest), so both compare the
 * same fields. Every property of the five entities is in exactly one of the two lists;
 * missingFromBothLists() names one that is in neither (a new column nobody decided about).
 *
 * Loaded by the unit autoloader (OCA\Learning\Tests\ → tests/) and by the integration bootstrap.
 */
final class PoolColumns {
    /** Columns an export does not carry, and why. Everything else must come back unchanged. */
    public const NOT_CARRIED = [
        Pool::class => [
            'id' => 'new id', 'userId' => 'the importing user owns the new pool',
            'createdAt' => 'set on import', 'updatedAt' => 'set on import',
            'reviewStatus' => 'review state of this instance, an import is published',
            'reviewerId' => 'review state', 'reviewedAt' => 'review state',
            'visibility' => 'legacy column, read and ignored',
        ],
        Question::class => [
            'id' => 'new id', 'poolId' => 'the new pool', 'userId' => 'the importing user',
            'createdAt' => 'set on import', 'updatedAt' => 'set on import',
            'reviewStatus' => 'review state of this instance, an import is published',
            'reviewerId' => 'review state', 'reviewedAt' => 'review state',
            'imagePath' => 'a path into this instance\'s app data; never taken from a file (docs: Round trip)',
            'lang' => 'no code path writes it (QuestionService::create() has no parameter); always null for app-created questions',
        ],
        Answer::class => [
            'id' => 'new id', 'questionId' => 'the new question',
        ],
        QuestionTranslation::class => [
            'id' => 'new id', 'questionId' => 'the new question', 'createdAt' => 'set on import',
        ],
        AnswerTranslation::class => [
            'id' => 'new id', 'answerId' => 'the new answer', 'createdAt' => 'set on import',
        ],
    ];

    /**
     * Columns that come back unchanged. Answer::position and the positions inside optionFeedback
     * are compared as answer order where stored positions may have gaps (the unit fixture).
     */
    public const COMPARED = [
        Pool::class => ['name', 'description', 'handbookKey', 'handbookTitle', 'chapterKey', 'chapterTitle', 'chapterOrder'],
        Question::class => ['text', 'explanation', 'difficulty', 'questionType', 'pbqSubtype', 'pbqConfig', 'instructorNote', 'noteVisible',
            'handbookKey', 'handbookTitle', 'examKey', 'chapterKey', 'chapterTitle', 'chapterOrder', 'scenario',
            'hint', 'backgroundFact', 'keyTakeaway', 'optionFeedback'],
        Answer::class => ['text', 'isCorrect', 'position'],
        QuestionTranslation::class => ['lang', 'text', 'explanation', 'scenario', 'hint', 'backgroundFact', 'keyTakeaway', 'optionFeedback'],
        AnswerTranslation::class => ['lang', 'text'],
    ];

    /**
     * Properties of $class (declared on the class itself, plus Entity's id) that are in neither
     * list, and listed ones the class does not have.
     *
     * @return array{missing: list<string>, unknown: list<string>}
     */
    public static function missingFromBothLists(string $class): array {
        $all = array_merge(['id'], self::properties($class));
        $listed = array_merge(self::COMPARED[$class] ?? [], array_keys(self::NOT_CARRIED[$class] ?? []));
        return [
            'missing' => array_values(array_diff($all, $listed)),
            'unknown' => array_values(array_diff($listed, $all)),
        ];
    }

    /**
     * Compared properties that hold no value (null, '' or false) on any of the given source
     * entities. A field the fixture never sets compares null with null and proves nothing — and
     * neither does a field that the code creating the source silently fails to store.
     *
     * @param list<object> $entities all of one class
     * @return list<string>
     */
    public static function unexercised(string $class, array $entities): array {
        $empty = [];
        foreach (self::COMPARED[$class] as $property) {
            $set = false;
            foreach ($entities as $entity) {
                if (!in_array($entity->{'get' . ucfirst($property)}(), [null, '', false], true)) {
                    $set = true;
                    break;
                }
            }
            if (!$set) {
                $empty[] = $property;
            }
        }
        return $empty;
    }

    /** @return list<string> protected properties declared on $class itself */
    public static function properties(string $class): array {
        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PROTECTED) as $p) {
            if ($p->getDeclaringClass()->getName() === $class) {
                $out[] = $p->getName();
            }
        }
        return $out;
    }
}
