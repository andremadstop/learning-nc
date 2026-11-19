<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertificationCycleService;
use OCA\Learning\Service\ConcurrentChangeException;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NothingToResetException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GitHub #84 / #87: the instructor's "Open a new certification cycle" for one learner.
 *
 * Instructors of the course only (owner or co-instructor, CourseService::assertInstructorOfCourse()).
 * A foreign course, an unknown course and a member of another course all answer the same 404, so
 * the route is no oracle for other courses' members. The POST keeps Nextcloud's CSRF check.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertificationCycleController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CertificationCycleService $cycles,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Per student member of the course (member_id = the course member row): whether a new cycle can be opened and
     * why ('revoked' | 'expired' | 'attempts' | null), the cycle an instructor opened if it is
     * still open, and the pools whose attempts are used up.
     *
     * @return DataResponse<Http::STATUS_OK, array{members: list<array{member_id: int, reason: string|null, new_cycle: array{reason: string, since: int}|null, exhausted_pool_ids: list<int>}>}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function states(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        try {
            $members = [];
            foreach ($this->cycles->statesForCourse($courseId, $this->userId) as $memberId => $state) {
                $members[] = ['member_id' => (int)$memberId] + $state;
            }
            return new DataResponse(['members' => $members]);
        } catch (DoesNotExistException | ForbiddenException $e) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Certification cycle states failed', ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Opens a new certification cycle for one student member: only exams of the new cycle count,
     * the attempts count from zero, and after a revoke the required documents are confirmed
     * again. Nothing is deleted; the opening is recorded in the compliance chain.
     *
     * @return DataResponse<Http::STATUS_OK, array{reason: string, since: int}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function open(int $courseId, int $memberId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        try {
            return new DataResponse($this->cycles->open($courseId, $memberId, $this->userId));
        } catch (DoesNotExistException | ForbiddenException $e) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (NothingToResetException $e) {
            return new DataResponse(['error' => NothingToResetException::CODE], Http::STATUS_CONFLICT);
        } catch (ConcurrentChangeException $e) {
            // another request opened a cycle for the same learner in between
            return new DataResponse(['error' => ConcurrentChangeException::CODE], Http::STATUS_CONFLICT);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                // another request opened a cycle for the same learner at the same moment
                return new DataResponse(['error' => ConcurrentChangeException::CODE], Http::STATUS_CONFLICT);
            }
            $this->logger->error('Opening a certification cycle failed', ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Opening a certification cycle failed', ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
