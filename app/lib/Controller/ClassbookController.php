<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;

class ClassbookController extends Controller {
    private ?string $userId;
    private CourseService $courseService;
    private CourseMemberMapper $courseMemberMapper;
    private UserTelosMapper $telosMapper;
    private IUserManager $userManager;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        CourseService $courseService,
        CourseMemberMapper $courseMemberMapper,
        UserTelosMapper $telosMapper,
        IUserManager $userManager
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->courseService = $courseService;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->telosMapper = $telosMapper;
        $this->userManager = $userManager;
    }

    /**
     * Get classbook profiles for a course.
     * Only returns users with visibility 'course' or 'public'.
     *
     * @NoAdminRequired
     */
    public function index(int $courseId): DataResponse {
        try {
            // Verify access
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }

        $members = $this->courseMemberMapper->findByCourse($courseId);
        $memberIds = array_map(fn($m) => $m->getUserId(), $members);
        $memberRoles = [];
        foreach ($members as $m) {
            $memberRoles[$m->getUserId()] = $m->getRole();
        }

        $allTelos = $this->telosMapper->findByUserIds($memberIds);
        $telosMap = [];
        foreach ($allTelos as $t) {
            $telosMap[$t->getUserId()] = $t;
        }

        $profiles = [];
        foreach ($memberIds as $uid) {
            $telos = $telosMap[$uid] ?? null;
            // Instructors always visible, students only if opt-in
            $isInstructor = ($memberRoles[$uid] ?? '') === 'instructor';
            $visibility = $telos ? $telos->getVisibility() : 'private';

            if (!$isInstructor && $visibility === 'private') {
                continue;
            }

            $user = $this->userManager->get($uid);
            $displayName = $user ? $user->getDisplayName() : $uid;
            $telosData = $telos ? $telos->getTelosData() : [];

            $profiles[] = [
                'user_id' => $uid,
                'display_name' => $displayName,
                'role' => $memberRoles[$uid] ?? 'student',
                'bio' => $telos ? $telos->getBio() : null,
                'help_offer' => $telos ? $telos->getHelpOfferList() : [],
                'help_wanted' => $telos ? $telos->getHelpWantedList() : [],
                'superpower' => $telosData['superpower'] ?? null,
                'why' => $telosData['why'] ?? null,
                'visibility' => $visibility,
            ];
        }

        // Instructors first, then alphabetical
        usort($profiles, function ($a, $b) {
            if ($a['role'] !== $b['role']) {
                return $a['role'] === 'instructor' ? -1 : 1;
            }
            return strcasecmp($a['display_name'], $b['display_name']);
        });

        return new DataResponse($profiles);
    }

    /**
     * Toggle own classbook visibility.
     *
     * @NoAdminRequired
     */
    public function toggleVisibility(int $courseId): DataResponse {
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }

        $telos = $this->telosMapper->findByUserIdOrNull($this->userId);
        if (!$telos) {
            return new DataResponse(['error' => 'Complete onboarding first'], Http::STATUS_BAD_REQUEST);
        }

        $current = $telos->getVisibility();
        $new = $current === 'private' ? 'course' : 'private';
        $telos->setVisibility($new);
        $telos->setUpdatedAt(time());
        $this->telosMapper->update($telos);

        return new DataResponse(['visibility' => $new]);
    }

    /**
     * Export own profile as vCard.
     *
     * @NoAdminRequired
     */
    public function exportVcard(int $courseId): Http\Response {
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access'], Http::STATUS_FORBIDDEN);
        }

        $user = $this->userManager->get($this->userId);
        $displayName = $user ? $user->getDisplayName() : $this->userId;
        $email = $user ? $user->getEMailAddress() : '';

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
