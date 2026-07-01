<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ClassbookController;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\ClassbookService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase 160 Plan 05 — ClassbookController USER-01 null-email safety.
 *
 * Confirms that exportVcard() handles IUser::getEMailAddress() === null gracefully:
 * the vCard is generated without an EMAIL line rather than crashing.
 *
 * REQUIREMENTS: USER-01 — email-null safety in ClassbookController (line 112 fix).
 *
 * @group user-01
 */
class ClassbookControllerTest extends TestCase {
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var CourseService&\PHPUnit\Framework\MockObject\MockObject */
    private $courseServiceMock;
    /** @var ClassbookService&\PHPUnit\Framework\MockObject\MockObject */
    private $classbookServiceMock;
    /** @var UserTelosMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMapperMock;
    /** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
    private $userManagerMock;

    protected function setUp(): void {
        $this->requestMock       = $this->createMock(IRequest::class);
        $this->courseServiceMock = $this->createMock(CourseService::class);
        $this->classbookServiceMock = $this->createMock(ClassbookService::class);
        $this->telosMapperMock   = $this->createMock(UserTelosMapper::class);
        $this->userManagerMock   = $this->createMock(IUserManager::class);
    }

    private function makeController(string $userId = 'alice'): ClassbookController {
        return new ClassbookController(
            'learning',
            $this->requestMock,
            $userId,
            $this->courseServiceMock,
            $this->classbookServiceMock,
            $this->telosMapperMock,
            $this->userManagerMock,
        );
    }

    /**
     * USER-01: When IUser::getEMailAddress() returns null (no email set in NC profile),
     * exportVcard() must NOT crash and must NOT emit an EMAIL line in the vCard.
     *
     * The old code `$user->getEMailAddress()` would assign null to $email;
     * the EMAIL block would still be added (null is truthy for non-strict `if ($email)`?
     * No — but the real risk is a TypeError in downstream string operations on null).
     * The fix `$user->getEMailAddress() ?? ''` turns null into empty string so the
     * `if ($email)` guard correctly suppresses the EMAIL line.
     */
    public function testNullEmailSafe(): void {
        // Arrange: user with a display name but NULL email
        $userMock = $this->createMock(IUser::class);
        $userMock->method('getDisplayName')->willReturn('Alice Wonderland');
        $userMock->method('getEMailAddress')->willReturn(null);

        $this->userManagerMock->method('get')->with('alice')->willReturn($userMock);

        // courseService->findById must not throw (simulates access granted).
        // Return [] — PHPUnit 10 rejects null for the declared array return type.
        $this->courseServiceMock->method('findById')->willReturn([]);

        // No telos profile → empty bio
        $this->telosMapperMock->method('findByUserIdOrNull')->willReturn(null);

        $controller = $this->makeController('alice');

        // Act
        $response = $controller->exportVcard(42);

        // Assert: response is a downloadable vCard (no crash)
        $this->assertInstanceOf(DataDownloadResponse::class, $response);

        // Get raw vCard content via reflection (DataDownloadResponse stores content internally)
        // DataDownloadResponse extends DataResponse; content is accessible via getData()
        // Actually DataDownloadResponse has a render() method that returns the content.
        // Use render() to get the actual string.
        $content = $response->render();

        // Must contain BEGIN/END VCARD
        $this->assertStringContainsString('BEGIN:VCARD', $content);
        $this->assertStringContainsString('END:VCARD', $content);

        // Must contain the display name
        $this->assertStringContainsString('FN:Alice Wonderland', $content);

        // MUST NOT contain an EMAIL line — null email coalesced to '' which suppresses the block
        $this->assertStringNotContainsString('EMAIL:', $content,
            'A null getEMailAddress() must not produce an EMAIL line in the vCard'
        );
    }

    /**
     * Sanity check: when email IS set, the EMAIL line appears in the vCard.
     */
    public function testNonNullEmailAppearsInVcard(): void {
        $userMock = $this->createMock(IUser::class);
        $userMock->method('getDisplayName')->willReturn('Bob Builder');
        $userMock->method('getEMailAddress')->willReturn('bob@example.com');

        $this->userManagerMock->method('get')->with('bob')->willReturn($userMock);
        $this->courseServiceMock->method('findById')->willReturn([]); // PHPUnit 10: null invalid for array
        $this->telosMapperMock->method('findByUserIdOrNull')->willReturn(null);

        $controller = $this->makeController('bob');
        $response = $controller->exportVcard(42);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $content = $response->render();
        $this->assertStringContainsString('EMAIL:bob@example.com', $content);
    }
}
