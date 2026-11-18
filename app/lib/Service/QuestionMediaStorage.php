<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionTrack;
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
 * kind is intro, dialog or opt<position> — the spoken explanation of the answer option at that
 * stored position (bundle format /2), e.g. q12-opt1-en.mp3. The row names its file: after the
 * answers were reordered a voice keeps the file it was written to (remapOptionTracks()), and a
 * new track whose plain name is still taken gets q12-opt1-en.2.mp3.
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
    private const FILE_PATTERN = '/^q(\d+)-(intro|dialog|opt\d{1,3})-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(?:\.\d{1,6})?\.mp3$/D';
    /** The kind of an option track: opt<position>. */
    public const OPTION_KIND_PATTERN = '/^opt(\d{1,3})$/D';

    public static function optionKind(int $position): string {
        return 'opt' . $position;
    }

    /** The answer position an option kind names, or null for intro/dialog. */
    public static function optionPosition(string $kind): ?int {
        return preg_match(self::OPTION_KIND_PATTERN, $kind, $m) === 1 ? (int)$m[1] : null;
    }

    public function __construct(
        private IAppData $appData,
        private QuestionTrackMapper $trackMapper,
        private QuestionCuesMapper $cuesMapper,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The file of a track. $variant > 1 (q12-opt1-en.2.mp3) when the plain name is still the
     * file of another row of the question: an option voice moved to another answer keeps its
     * file (remapOptionTracks()), so its old name is taken until that row goes.
     */
    public static function fileName(int $questionId, string $kind, string $lang, int $variant = 1): string {
        return 'q' . $questionId . '-' . $kind . '-' . $lang . ($variant > 1 ? '.' . $variant : '') . '.mp3';
    }

    /**
     * The spoken explanations per answer option follow their answers when a question is saved
     * with other answers — the same positionMap as option_feedback (RichFeedbackFormat): a voice
     * whose answer has a new position gets that position's kind, a voice whose answer is gone
     * (or that explained no answer any more) is dropped, so a voice never plays under another
     * answer. Rows only, no file is touched: the caller's transaction can roll it all back. A
     * moved row keeps its file; a dropped row's file is the sweep's. Moved rows are deleted
     * first and inserted again, since (question, kind, lang) is unique and two voices may swap.
     *
     * @param array<int, int> $map old stored position => new stored position
     */
    public function remapOptionTracks(int $questionId, array $map): void {
        $moved = [];
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            $position = self::optionPosition($track->getKind());
            if ($position === null || ($map[$position] ?? null) === $position) {
                continue;
            }
            $this->trackMapper->delete($track);
            if (isset($map[$position])) {
                $moved[] = [$track, $map[$position]];
            }
        }
        foreach ($moved as [$track, $target]) {
            $copy = new QuestionTrack();
            $copy->setQuestionId($track->getQuestionId());
            $copy->setPoolId($track->getPoolId());
            $copy->setKind(self::optionKind($target));
            $copy->setLang($track->getLang());
            $copy->setFileName($track->getFileName());
            $copy->setSize($track->getSize());
            $copy->setSha256($track->getSha256());
            $copy->setTimeline($track->getTimeline());
            $copy->setDurationMs($track->getDurationMs());
            $copy->setUpdatedAt(time());
            $this->trackMapper->insert($copy);
        }
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

    /**
     * Whether the file is there with the given size. Any error reading it counts as "not there":
     * the caller (the import) then writes it again rather than trusting a row whose file is gone.
     */
    public function holds(int $poolId, string $fileName, int $size): bool {
        try {
            return $this->get($poolId, $fileName)->getSize() === $size;
        } catch (\Throwable $e) {
            return false;
        }
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
