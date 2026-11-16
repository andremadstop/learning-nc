<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<QuestionTrack> */
class QuestionTrackMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_qmedia_tracks', QuestionTrack::class);
    }

    public function findById(int $id): ?QuestionTrack {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            /** @var QuestionTrack $track */
            $track = $this->findEntity($qb);
            return $track;
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /** @return list<QuestionTrack> ordered by kind, then language */
    public function findByQuestion(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
           ->orderBy('kind', 'ASC')
           ->addOrderBy('lang', 'ASC');
        return array_values($this->findEntities($qb));
    }

    /**
     * File names of the tracks stored for the questions of a pool, by question id (the orphan
     * sweep keeps exactly these files).
     *
     * @return array<int, list<string>>
     */
    public function fileNamesByPool(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('question_id', 'file_name')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $out = [];
        while (($row = $result->fetch()) !== false) {
            $out[(int)$row['question_id']][] = (string)$row['file_name'];
        }
        $result->closeCursor();
        return $out;
    }

    public function deleteByQuestion(int $questionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteByPool(int $poolId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
