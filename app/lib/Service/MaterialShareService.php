<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Read-only access to a course's material folder for course members, and the URL of the stock
 * Text viewer for a material file (GitHub #110).
 *
 * The bytes live in the course owner's home and are served by DocumentController::download()
 * through the owner's view, so a member has no access to the folder in Files. Markdown is now
 * opened in the Nextcloud Text viewer (/f/{fileid}), which resolves the file through the
 * member's own folder — a member therefore needs a read share of the material folder first.
 *
 * The share is created lazily the first time a member opens a material, and only ever:
 *  - read-only (Constants::PERMISSION_READ): Text derives its read-only mode from the missing
 *    write permission (Text FileService: $readOnly = !$file->isUpdateable());
 *  - without "download disabled": Text 9.0.2 refuses a read-only share whose download is
 *    disabled (nextcloud/text#9581, backported in nextcloud/text#9586), and the bytes are
 *    reachable through the download endpoint anyway;
 *  - marked with the note learning:course:<id>: revoke*() deletes only shares with that note,
 *    so a share the instructor made by hand is never touched;
 *  - best effort: every failure means "no redirect", never "no material" — download() falls
 *    back to the plain text response it has always served.
 */
class MaterialShareService {
    /** Route of the file page that opens the viewer for a file id (files app, "view#showFile"). */
    public const VIEWER_ROUTE = 'files.view.showfile';

    public function __construct(
        private CourseMapper $courseMapper,
        private IRootFolder $rootFolder,
        private IShareManager $shareManager,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /** The marker note of this course's shares; the only thing revoke*() ever deletes. */
    public static function noteFor(int $courseId): string {
        return 'learning:course:' . $courseId;
    }

    /**
     * Where a course member sees this material file in the stock Text viewer, or null when the
     * plain text response must serve it: no Text app for this user, no file id, no read access
     * to the folder (sharing refused), or no viewer route.
     */
    public function textViewUrl(Course $course, string $memberId, \OCP\Files\File $file): ?string {
        if ($memberId === '') {
            return null;
        }
        try {
            $fileId = $file->getId();
            if ($fileId <= 0) {
                return null;
            }
            $user = $this->userManager->get($memberId);
            if ($user === null || !$this->appManager->isEnabledForUser('text', $user)) {
                return null;
            }
            if (!$this->ensureLearnerAccess($course, $memberId)) {
                return null;
            }
            return $this->urlGenerator->linkToRoute(self::VIEWER_ROUTE, ['fileid' => (string)$fileId]);
        } catch (\Throwable $e) {
            $this->logger->warning('MaterialShareService: no Text viewer for this material: ' . $e->getMessage(), ['app' => 'learning']);
            return null;
        }
    }

    /**
     * Make sure the member may read the course's material folder (read-only).
     *
     * @return bool true when the member can see the folder now (or always could)
     */
    public function ensureLearnerAccess(Course $course, string $learnerId): bool {
        $owner = (string)$course->getInstructorId();
        if ($learnerId === '' || $learnerId === $owner) {
            // the owner of the folder needs no share of it
            return true;
        }
        try {
            $folder = $this->materialFolder($course);
        } catch (\Throwable $e) {
            $this->logger->debug('MaterialShareService: no material folder: ' . $e->getMessage(), ['app' => 'learning']);
            return false;
        }
        try {
            $share = $this->shareManager->newShare();
            $share->setNode($folder);
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($learnerId);
            $share->setPermissions(Constants::PERMISSION_READ);
            $share->setSharedBy($owner);
            $share->setNote(self::noteFor((int)$course->getId()));
            $this->shareManager->createShare($share);
            return true;
        } catch (AlreadySharedException $e) {
            // readable through a share of exactly this folder already: access is all we want
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('MaterialShareService: could not share the material folder: ' . $e->getMessage(), ['app' => 'learning']);
            return false;
        }
    }

    /** Take the material folder away from one member (the membership ended). */
    public function revokeLearnerAccess(Course $course, string $learnerId): void {
        if ($learnerId === '' || $learnerId === (string)$course->getInstructorId()) {
            return;
        }
        try {
            $folder = $this->materialFolder($course);
            foreach ($this->courseShares($course, $folder) as $share) {
                if ($share->getSharedWith() === $learnerId) {
                    $this->shareManager->deleteShare($share);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('MaterialShareService: could not revoke the material share: ' . $e->getMessage(), ['app' => 'learning']);
        }
    }

    /**
     * Take the material folder away from everyone: the course points at another folder now, and
     * the shares of the old one are the app's, not the instructor's. Shares without this
     * course's note are left alone.
     */
    public function revokeCourseShares(int $courseId, string $folderPath): void {
        if ($folderPath === '' || $folderPath === '/') {
            return;
        }
        try {
            $course = $this->courseMapper->findById($courseId);
            $userFolder = $this->rootFolder->getUserFolder((string)$course->getInstructorId());
            $folder = $userFolder->get($folderPath);
            if (!($folder instanceof Folder)) {
                return;
            }
            foreach ($this->courseShares($course, $folder) as $share) {
                $this->shareManager->deleteShare($share);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('MaterialShareService: could not revoke the shares of the old folder: ' . $e->getMessage(), ['app' => 'learning']);
        }
    }

    /**
     * The shares of that folder this app created for that course (its note), by the course
     * owner. A share the instructor made by hand carries no note and survives every revoke.
     *
     * @return list<IShare>
     */
    private function courseShares(Course $course, Folder $folder): array {
        $note = self::noteFor((int)$course->getId());
        $shares = $this->shareManager->getSharesBy(
            (string)$course->getInstructorId(),
            IShare::TYPE_USER,
            $folder,
            false,
            -1
        );
        $ours = [];
        foreach ($shares as $share) {
            if ($share->getNote() === $note) {
                $ours[] = $share;
            }
        }
        return $ours;
    }

    /** @throws NotFoundException the course has no linked material folder, or it is gone */
    private function materialFolder(Course $course): Folder {
        $path = $course->getMaterialFolder();
        if ($path === null || $path === '' || $path === '/') {
            throw new NotFoundException('No material folder');
        }
        $userFolder = $this->rootFolder->getUserFolder((string)$course->getInstructorId());
        $folder = $userFolder->get($path);
        if (!($folder instanceof Folder)) {
            throw new NotFoundException('Material path is not a folder');
        }
        return $folder;
    }
}
