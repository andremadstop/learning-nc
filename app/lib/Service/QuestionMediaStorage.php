<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionTrackMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Where the media of a question lives (GitHub #66): the app's own app data, never a user's
 * files. One folder per pool, one file per track:
 *
 *   appdata_<instance>/learning/qmedia-<poolId>/q<questionId>-<kind>-<lang>.mp3
 *
 * Flat per pool (no folder per question) because ISimpleFolder lists files only, and the orphan
 * sweep must see every file to find the ones nothing points at any more.
 *
 * The rows (learning_qmedia_tracks / _cues) cascade with their question and pool in the
 * database; the files are removed here: by PoolService::delete and QuestionService::delete, and
 * for every other way a question or pool disappears (a cascade, a failed delete, an older
 * version) by sweepOrphans(), run by the repair step RemoveOrphanedQuestionMedia and the daily
 * ConsistencyCheckJob. No access checks here — QuestionMediaService does those.
 */
class QuestionMediaStorage {
    public const FOLDER_PREFIX = 'qmedia-';
    private const FILE_PATTERN = '/^q(\d+)-(intro|dialog)-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.mp3$/D';

    public function __construct(
        private IAppData $appData,
        private QuestionTrackMapper $trackMapper,
        private QuestionCuesMapper $cuesMapper,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public static function fileName(int $questionId, string $kind, string $lang): string {
        return 'q' . $questionId . '-' . $kind . '-' . $lang . '.mp3';
    }

    public function put(int $poolId, string $fileName, string $bytes): void {
        $folder = $this->poolFolder($poolId, true);
        if ($folder === null) {
            throw new \RuntimeException('Cannot create the media folder of pool ' . $poolId);
        }
        if ($folder->fileExists($fileName)) {
            $folder->getFile($fileName)->putContent($bytes);
        } else {
            $folder->newFile($fileName, $bytes);
        }
    }

    /**
     * @throws FilesNotFoundException
     */
    public function get(int $poolId, string $fileName): ISimpleFile {
        $folder = $this->poolFolder($poolId, false);
        if ($folder === null) {
            throw new FilesNotFoundException('No media folder for pool ' . $poolId);
        }
        return $folder->getFile($fileName);
    }

    public function remove(int $poolId, string $fileName): void {
        $folder = $this->poolFolder($poolId, false);
        if ($folder === null || !$folder->fileExists($fileName)) {
            return;
        }
        $folder->getFile($fileName)->delete();
    }

    /**
     * Rows and files of one question, after the question itself is gone. Best effort: a failure
     * is logged, never thrown (the delete already happened), and the sweep removes what is left.
     */
    public function deleteForQuestion(int $questionId, int $poolId): void {
        try {
            $this->trackMapper->deleteByQuestion($questionId);
            $this->cuesMapper->deleteByQuestion($questionId);
            $folder = $this->poolFolder($poolId, false);
            if ($folder === null) {
                return;
            }
            foreach ($folder->getDirectoryListing() as $file) {
                if (self::questionOf($file->getName()) === $questionId) {
                    $file->delete();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Question media: files of question {question} not removed: {error}', [
                'app' => 'learning', 'question' => $questionId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Rows and the folder of one pool; best effort like deleteForQuestion(). */
    public function deleteForPool(int $poolId): void {
        try {
            $this->trackMapper->deleteByPool($poolId);
            $this->cuesMapper->deleteByPool($poolId);
            $this->poolFolder($poolId, false)?->delete();
        } catch (\Throwable $e) {
            $this->logger->warning('Question media: folder of pool {pool} not removed: {error}', [
                'app' => 'learning', 'pool' => $poolId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove what no question points at any more: rows whose question is gone (the database
     * cascade normally does that), folders of pools that are gone, and files that no track row
     * names (the question was deleted, or a track was dropped from a later import) once they are
     * an hour old.
     *
     * @param int $minAgeSeconds files younger than this are kept (0 in tests)
     * @return array{rows: int, folders: int, files: int}
     */
    public function sweepOrphans(int $minAgeSeconds = 3600): array {
        $removed = ['rows' => 0, 'folders' => 0, 'files' => 0];
        $removed['rows'] += $this->deleteRowsWithoutQuestion('learning_qmedia_tracks');
        $removed['rows'] += $this->deleteRowsWithoutQuestion('learning_qmedia_cues');

        try {
            $folders = $this->appData->getDirectoryListing();
        } catch (\Throwable $e) {
            return $removed;
        }
        foreach ($folders as $folder) {
            $name = $folder->getName();
            if (!str_starts_with($name, self::FOLDER_PREFIX)) {
                continue;
            }
            $poolId = substr($name, strlen(self::FOLDER_PREFIX));
            if (!ctype_digit($poolId)) {
                continue;
            }
            try {
                if (!$this->poolExists((int)$poolId)) {
                    $folder->delete();
                    $removed['folders']++;
                    continue;
                }
                $kept = [];
                foreach ($this->trackMapper->fileNamesByPool((int)$poolId) as $names) {
                    foreach ($names as $fileName) {
                        $kept[$fileName] = true;
                    }
                }
                foreach ($folder->getDirectoryListing() as $file) {
                    // A file younger than an hour may belong to an import that has written it
                    // but not yet its row.
                    if (!isset($kept[$file->getName()]) && $file->getMTime() <= time() - $minAgeSeconds) {
                        $file->delete();
                        $removed['files']++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Question media: sweep of {folder} failed: {error}', [
                    'app' => 'learning', 'folder' => $name, 'error' => $e->getMessage(),
                ]);
            }
        }
        return $removed;
    }

    /** The question id a stored file name belongs to, or null for a name this class never writes. */
    public static function questionOf(string $fileName): ?int {
        return preg_match(self::FILE_PATTERN, $fileName, $m) === 1 ? (int)$m[1] : null;
    }

    private function poolFolder(int $poolId, bool $create): ?ISimpleFolder {
        $name = self::FOLDER_PREFIX . $poolId;
        try {
            return $this->appData->getFolder($name);
        } catch (FilesNotFoundException $e) {
            return $create ? $this->appData->newFolder($name) : null;
        }
    }

    private function poolExists(int $poolId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    private function deleteRowsWithoutQuestion(string $table): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.id')
           ->from($table, 'm')
           ->leftJoin('m', 'learning_questions', 'q', $qb->expr()->eq('m.question_id', 'q.id'))
           ->where($qb->expr()->isNull('q.id'));
        $result = $qb->executeQuery();
        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (int)$row['id'];
        }
        $result->closeCursor();
        foreach (array_chunk($ids, 500) as $chunk) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete($table)
                   ->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $delete->executeStatement();
        }
        return count($ids);
    }
}
