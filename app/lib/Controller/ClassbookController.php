<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\ClassbookService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use OCP\IUserManager;

class ClassbookController extends Controller {
    private ?string $userId;
    private CourseService $courseService;
    private ClassbookService $classbookService;
    private \OCA\Learning\Db\UserTelosMapper $telosMapper;
    private IUserManager $userManager;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        CourseService $courseService,
        ClassbookService $classbookService,
        \OCA\Learning\Db\UserTelosMapper $telosMapper,
        IUserManager $userManager
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->courseService = $courseService;
        $this->classbookService = $classbookService;
        $this->telosMapper = $telosMapper;
        $this->userManager = $userManager;
    }

    /**
     * Get classbook profiles for a course.
     * Only returns users with visibility 'course' or 'public'.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function index(int $courseId): DataResponse {
        try {
            return new DataResponse($this->classbookService->getClassbook($courseId, (string)$this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Give kudos to another course member.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function giveKudos(int $courseId, string $toUser, string $message = ''): DataResponse {
        try {
            return new DataResponse($this->classbookService->giveKudos($courseId, (string)$this->userId, $toUser, $message), Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Set (or, without a target, toggle) own classbook visibility.
     *
     * GitHub #50: the visibility is global, so a toggle relative to the server value flipped
     * the wrong way when another tab had changed it. The register sends the state the learner
     * asked for ('course' or 'private'), which is set idempotently.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function toggleVisibility(int $courseId, ?string $visibility = null): DataResponse {
        if ($visibility !== null && !in_array($visibility, ['course', 'private'], true)) {
            return new DataResponse(['error' => 'Invalid visibility'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $allowed = $this->classbookService->canToggleVisibility($courseId, (string)$this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }
        // GitHub #50: an instructor (or the owner, who has no member row) has no learner profile
        // in this register. The switch used to flip their global visibility and report success.
        if (!$allowed) {
            return new DataResponse(['error' => 'Only learners of this course can change their visibility here'], Http::STATUS_FORBIDDEN);
        }

        $telos = $this->telosMapper->findByUserIdOrNull($this->userId);
        if (!$telos) {
            return new DataResponse(['error' => 'Complete onboarding first'], Http::STATUS_BAD_REQUEST);
        }

        $current = $telos->getVisibility();
        $new = $visibility ?? ($current === 'private' ? 'course' : 'private');
        if ($new !== $current) {
            $telos->setVisibility($new);
            $telos->setUpdatedAt(time());
            $this->telosMapper->update($telos);
        }

        return new DataResponse(['visibility' => $new]);
    }

    /**
     * Export own profile as vCard.
     *
     * A navigation download (window.location from the Participants tab), so no request token is
     * sent (GitHub #45). Read-only GET of the caller's own profile; course membership is checked.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportVcard(int $courseId): Http\Response {
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }

        $user = $this->userManager->get($this->userId);
        $displayName = $user ? $user->getDisplayName() : $this->userId;
        $email = $user ? ($user->getEMailAddress() ?? '') : '';

        $telos = $this->telosMapper->findByUserIdOrNull($this->userId);
        $bio = $telos ? ($telos->getBio() ?? '') : '';

        $vcard = "BEGIN:VCARD\r\n";
        $vcard .= "VERSION:3.0\r\n";
        $vcard .= 'FN:' . $this->escapeVcard($displayName) . "\r\n";
        if ($email) {
            $vcard .= 'EMAIL:' . $this->escapeVcard($email) . "\r\n";
        }
        if ($bio) {
            $vcard .= 'NOTE:' . $this->escapeVcard($bio) . "\r\n";
        }
        $vcard .= "END:VCARD\r\n";

        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $displayName);
        return new DataDownloadResponse($vcard, $safeName . '.vcf', 'text/vcard');
    }

    private function escapeVcard(string $value): string {
        return str_replace(["\n", "\r", ',', ';'], ['\\n', '', '\\,', '\\;'], $value);
    }
}
