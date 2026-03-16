<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AnswerTranslationMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_ans_translations', AnswerTranslation::class);
    }

    public function findByAnswer(int $answerId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('answer_id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function findByAnswerAndLang(int $answerId, string $lang): ?AnswerTranslation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('answer_id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('lang', $qb->createNamedParameter($lang)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    public function deleteByAnswer(int $answerId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('answer_id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
