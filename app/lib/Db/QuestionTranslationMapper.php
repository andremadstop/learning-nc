<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class QuestionTranslationMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_qst_translations', QuestionTranslation::class);
    }

    public function findByQuestion(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function findByQuestionAndLang(int $questionId, string $lang): ?QuestionTranslation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('lang', $qb->createNamedParameter($lang)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * @param int[] $questionIds
     * @return QuestionTranslation[]
     */
    public function findByQuestionsAndLang(array $questionIds, string $lang): array {
        if (empty($questionIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('lang', $qb->createNamedParameter($lang)));

        return $this->findEntities($qb);
    }

    public function deleteByQuestion(int $questionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
