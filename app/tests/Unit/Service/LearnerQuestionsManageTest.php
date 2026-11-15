<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketNotifier;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #59 (rest): a question from a learner could only be answered. Instructors of the course
 * now archive (hide from the inbox, restorable) and delete questions, a learner withdraws their
 * own question while it has no answer, and the tab shows how many questions wait.
 *
 * Rights: only instructors of the course the ticket is routed to. The course in the URL is no
 * authority on its own (IDOR): a ticket of course B reached through course A is "not found".
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerQuestionsManageTest extends TestCase {
    /** @var array<int, SupportTicket> */
    private array $tickets = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<array{0: string, 1: int}> */
    private array $notices = [];

    private function ticket(int $id, array $fields = []): SupportTicket {
        $ticket = new SupportTicket();
        $ticket->setId($id);
        $ticket->setUserId($fields['user'] ?? 'learner');
        $ticket->setSubject('Frist');
        $ticket->setMessage('Bis wann?');
        $ticket->setStatus($fields['status'] ?? 'open');
        $ticket->setCreatedAt(100);
        $ticket->setRoutingTargetType($fields['routing'] ?? 'course_instructor');
        $ticket->setRoutingCourseId(array_key_exists('course', $fields) ? $fields['course'] : 7);
        $ticket->setAnswerText($fields['answer'] ?? null);
        $this->tickets[$id] = $ticket;
        return $ticket;
    }

    /**
     * @param array<int, bool> $manages course id => caller is instructor of it
     * @return array{0: SupportTicketService, 1: SupportTicketMapper}
     */
    private function service(array $manages = [7 => true], bool $member = true, bool $deleteHits = true): array {
        $mapper = $this->createMock(SupportTicketMapper::class);
        $mapper->method('findById')->willReturnCallback(function (int $id): SupportTicket {
            if (!isset($this->tickets[$id])) {
                throw new DoesNotExistException('none');
            }
            return $this->tickets[$id];
        });
        $mapper->method('update')->willReturnArgument(0);
        $mapper->method('delete')->willReturnCallback(function (SupportTicket $t): SupportTicket {
            $this->deleted[] = (int)$t->getId();
            return $t;
        });
        $mapper->method('deleteOpenOwnQuestion')->willReturnCallback(function (int $id) use ($deleteHits): bool {
            if ($deleteHits) {
                $this->deleted[] = $id;
            }
            return $deleteHits;
        });
        $mapper->method('countOpenByInstructorCourse')->willReturn(3);

        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturnCallback(fn (int $courseId): bool => $manages[$courseId] ?? false);
        if ($member) {
            $courses->method('findById')->willReturn(['id' => 7, 'title' => 'Arbeitsschutz', 'is_instructor' => false]);
        } else {
            $courses->method('findById')->willThrowException(new DoesNotExistException('nope'));
        }

        $notifier = $this->createMock(SupportTicketNotifier::class);
        $notifier->method('questionSettled')->willReturnCallback(function (SupportTicket $t): void {
            $this->notices[] = ['settled', (int)$t->getId()];
        });
        $notifier->method('ticketRemoved')->willReturnCallback(function (SupportTicket $t): void {
            $this->notices[] = ['removed', (int)$t->getId()];
        });

        $gemini = $this->createMock(GeminiService::class);
        $service = new SupportTicketService($mapper, $courses, $gemini, $this->createMock(TelosService::class), $notifier);
        return [$service, $mapper];
    }

    private function controller(SupportTicketService $service, string $uid): SupportTicketController {
        return new SupportTicketController('learning', $this->createMock(IRequest::class), $service,
            $this->createMock(CourseService::class), $this->createMock(IGroupManager::class), $uid);
    }

    // ── archive / restore ─────────────────────────────────────────────────────

    public function testAnInstructorArchivesAQuestionAndItsNewQuestionNoticeGoes(): void {
        $this->ticket(11);
        [$service] = $this->service();

        $result = $service->setCourseTicketArchived(7, 11, 'owner', true);

        $this->assertSame('archived', $result['status']);
        $this->assertSame([['settled', 11]], $this->notices);
    }

    public function testRestoringBringsBackOpenOrAnsweredWhicheverItWas(): void {
        $this->ticket(11, ['status' => 'archived']);
        $this->ticket(12, ['status' => 'archived', 'answer' => 'Bis Freitag.']);
        [$service] = $this->service();

        $this->assertSame('open', $service->setCourseTicketArchived(7, 11, 'owner', false)['status']);
        $this->assertSame('answered', $service->setCourseTicketArchived(7, 12, 'owner', false)['status']);
        $this->assertSame([], $this->notices);
    }

    public function testTheLearnerStillSeesAnArchivedQuestionAsOpenOrAnswered(): void {
        $mapperTickets = [
            $this->ticket(11, ['status' => 'archived']),
            $this->ticket(12, ['status' => 'archived', 'answer' => 'Bis Freitag.']),
        ];
        [$service, $mapper] = $this->service();
        $mapper->method('findByUserAndRoutingCourse')->willReturn($mapperTickets);
        $mapper->method('findByUser')->willReturn($mapperTickets);

        $course = $service->listMyCourseQuestions(7, 'learner')['questions'];
        $this->assertSame(['open', 'answered'], array_column($course, 'status'));
        // VirtuProf's "My tickets" is a learner view as well
        $this->assertSame(['open', 'answered'], array_column($service->listMine('learner'), 'status'));
    }

    // ── rights and IDOR ───────────────────────────────────────────────────────

    public function testALearnerOrOutsiderCannotArchiveOrDelete(): void {
        $this->ticket(11);
        [$service, $mapper] = $this->service([7 => false]);
        $mapper->expects($this->never())->method('update');
        $mapper->expects($this->never())->method('delete');

        foreach ([
            fn () => $service->setCourseTicketArchived(7, 11, 'learner', true),
            fn () => $service->setCourseTicketArchived(7, 11, 'learner', false),
            fn () => $service->deleteCourseTicket(7, 11, 'learner'),
        ] as $call) {
            try {
                $call();
                $this->fail('a non-instructor must not manage the question');
            } catch (ForbiddenException $e) {
            }
        }
        $this->assertSame('open', $this->tickets[11]->getStatus());
    }

    public function testAQuestionOfAnotherCourseIsNotFoundThroughTheOwnCourse(): void {
        // the caller teaches course 7 only; ticket 21 belongs to course 8
        $this->ticket(21, ['course' => 8]);
        [$service, $mapper] = $this->service([7 => true, 8 => false]);
        $mapper->expects($this->never())->method('update');
        $mapper->expects($this->never())->method('delete');

        try {
            $service->deleteCourseTicket(7, 21, 'owner');
            $this->fail('course 7 in the URL must not reach a ticket of course 8');
        } catch (DoesNotExistException $e) {
        }
        try {
            $service->setCourseTicketArchived(8, 21, 'owner', true);
            $this->fail('naming course 8 does not make the caller its instructor');
        } catch (ForbiddenException $e) {
        }
        $this->assertSame([], $this->deleted);
    }

    public function testAnAdminInboxTicketIsNotReachableThroughACourse(): void {
        $this->ticket(31, ['routing' => 'admin', 'course' => null]);
        [$service] = $this->service();
        $this->expectException(DoesNotExistException::class);
        $service->deleteCourseTicket(7, 31, 'owner');
    }

    public function testDeletingTakesEveryNotificationOfTheQuestionAlong(): void {
        $this->ticket(11, ['status' => 'answered', 'answer' => 'Bis Freitag.']);
        [$service] = $this->service();

        $service->deleteCourseTicket(7, 11, 'owner');

        $this->assertSame([11], $this->deleted);
        $this->assertSame([['removed', 11]], $this->notices);
    }

    public function testTheEndpointsAnswer403And404AndTheOpenCount(): void {
        $this->ticket(11);
        $this->ticket(21, ['course' => 8]);
        [$service] = $this->service([7 => true]);

        $this->assertSame(Http::STATUS_OK, $this->controller($service, 'owner')->archiveCourseTicket(7, 11)->getStatus());
        $this->assertSame(Http::STATUS_OK, $this->controller($service, 'owner')->restoreCourseTicket(7, 11)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($service, 'owner')->deleteCourseTicket(7, 21)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($service, 'owner')->deleteCourseTicket(7, 999)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, 'owner')->archiveCourseTicket(8, 21)->getStatus());

        [$learnerService] = $this->service([7 => false]);
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($learnerService, 'learner')->deleteCourseTicket(7, 11)->getStatus());
    }

    public function testOnlyInstructorsOfTheCourseReadTheOpenCount(): void {
        [$service] = $this->service();
        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturnCallback(fn (int $c, string $uid): bool => $uid === 'owner');

        $owner = new SupportTicketController('learning', $this->createMock(IRequest::class), $service, $courses, $this->createMock(IGroupManager::class), 'owner');
        $this->assertSame(['open_count' => 3], $owner->openCount(7)->getData());

        $learner = new SupportTicketController('learning', $this->createMock(IRequest::class), $service, $courses, $this->createMock(IGroupManager::class), 'learner');
        $this->assertSame(Http::STATUS_FORBIDDEN, $learner->openCount(7)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $learner->instructorList(7)->getStatus());
    }

    public function testTheInboxAndTheArchiveAreListedApart(): void {
        [$service, $mapper] = $this->service();
        $mapper->expects($this->exactly(2))->method('findByInstructorCourse')
            ->willReturnCallback(function (int $courseId, int $limit, bool $archived): array {
                return [$this->ticket($archived ? 2 : 1, ['status' => $archived ? 'archived' : 'open'])];
            });
        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturn(true);
        $controller = new SupportTicketController('learning', $this->createMock(IRequest::class), $service, $courses, $this->createMock(IGroupManager::class), 'owner');

        $inbox = $controller->instructorList(7)->getData();
        $archive = $controller->instructorList(7, 50, true)->getData();

        $this->assertSame(['open'], array_column($inbox['tickets'], 'status'));
        $this->assertSame(['archived'], array_column($archive['tickets'], 'status'));
        $this->assertSame(3, $inbox['open_count']);
    }

    // ── withdraw ──────────────────────────────────────────────────────────────

    public function testALearnerWithdrawsTheirOwnUnansweredQuestion(): void {
        $this->ticket(11);
        [$service] = $this->service([7 => false]);

        $service->withdrawCourseQuestion(7, 11, 'learner');

        $this->assertSame([11], $this->deleted);
        $this->assertSame([['removed', 11]], $this->notices);
    }

    public function testNobodyWithdrawsSomeoneElsesQuestionOrOneOfAnotherCourse(): void {
        $this->ticket(11, ['user' => 'other']);
        $this->ticket(21, ['course' => 8]);
        [$service, $mapper] = $this->service([7 => false]);
        $mapper->expects($this->never())->method('deleteOpenOwnQuestion');

        foreach ([11, 21] as $id) {
            try {
                $service->withdrawCourseQuestion(7, $id, 'learner');
                $this->fail('ticket ' . $id . ' must not be withdrawn by this learner');
            } catch (DoesNotExistException $e) {
            }
        }
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($service, 'learner')->withdrawCourseQuestion(7, 11)->getStatus());
    }

    public function testAnAnsweredQuestionCannotBeWithdrawn(): void {
        // the conditional DELETE finds no unanswered row: an answer came first
        $this->ticket(11, ['status' => 'answered', 'answer' => 'Bis Freitag.']);
        [$service] = $this->service([7 => false], true, false);

        $this->assertSame(Http::STATUS_CONFLICT, $this->controller($service, 'learner')->withdrawCourseQuestion(7, 11)->getStatus());
        $this->assertSame([], $this->notices);
    }

    public function testAnOutsiderCannotWithdraw(): void {
        $this->ticket(11, ['user' => 'stranger']);
        [$service, $mapper] = $this->service([7 => false], false);
        $mapper->expects($this->never())->method('deleteOpenOwnQuestion');
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($service, 'stranger')->withdrawCourseQuestion(7, 11)->getStatus());
    }
}
