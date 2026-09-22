<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** @extends QBMapper<CurriculumScope> */
class CurriculumScopeMapper extends QBMapper {
    private ?LoggerInterface $logger;

    public function __construct(IDBConnection $db, ?LoggerInterface $logger = null) {
        parent::__construct($db, 'learning_course_curriculum_scopes', CurriculumScope::class);
        $this->logger = $logger;
    }

    /**
     * The curriculum scope for a course, or null if there is none.
     *
     * Null also covers "the table does not exist" (Codeberg #7). That table is produced by
     * a rename migration which aborts on failure rather than creating an empty table over
     * existing data, so an install can legitimately end up without it. Every caller already
     * treats null as "no scope configured" and falls back to showing everything, so
     * degrading here keeps the course view alive instead of failing the whole page.
     *
     * Any other database error is rethrown untouched — swallowing those would trade a
     * visible outage for a silently wrong answer.
     */
    public function findByCourse(int $courseId): ?CurriculumScope {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)));
        try {
            /** @var CurriculumScope $entity */
            $entity = $this->findEntity($qb);
            return $entity;
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            if (!DbErrors::isMissingTable($e)) {
                throw $e;
            }
            // Loud in the log, soft in the UI: an admin needs to see that a migration did
            // not complete, but a learner should still be able to open the course.
            $this->logger?->warning(
                'learning: table ' . $this->getTableName() . ' is missing — curriculum scoping disabled for this request. '
                . 'This usually means migration Version009900 did not complete; running "occ upgrade" should repair it.',
                ['exception' => $e, 'app' => 'learning']
            );
            return null;
        }
    }
}
