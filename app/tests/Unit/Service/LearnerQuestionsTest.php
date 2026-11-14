<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Notification\Notifier;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketNotifier;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #59: with VirtuProf switched off a learner had no way to ask the instructors of a
 * course, and nobody was told of a new question, an answer or a changed answer. A learner now
 * asks from the course's communication tab (POST /api/courses/{id}/questions), reads their own
 * questions there (GET .../questions/mine), and Nextcloud notifications go to the course's
 * instructors (new question) and to the learner (answer, changed answer).
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerQuestionsTest extends TestCase {
    /** @var list<array{user: string, subject: string, params: array}> */
    private array $sent = [];
    /** @var list<array{user: ?string, subject: ?string}> */
    private array $dismissed = [];
    private ?string $failFor = null;

    private function ticket(array $fields = []): SupportTicket {
        $ticket = new SupportTicket();
        $ticket->setId($fields['id'] ?? 11);
        $ticket->setUserId($fields['user'] ?? 'learner');
        $ticket->setSubject($fields['subject'] ?? 'Frist');
        $ticket->setMessage('Bis wann?');
        $ticket->setStatus($fields['status'] ?? 'open');
        $ticket->setCreatedAt(100);
        $ticket->setRoutingTargetType($fields['routing'] ?? 'course_instructor');
        $ticket->setRoutingCourseId(array_key_exists('course', $fields) ? $fields['course'] : 7);
        $ticket->setAnswerText($fields['answer'] ?? null);
        $ticket->setAiLabel('FAQ');
        $ticket->setAiDraftAnswer('Entwurf');
        return $ticket;
    }

    private function notifier(): SupportTicketNotifier {
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(function () {
            $n = $this->createMock(INotification::class);
            $state = new \ArrayObject(['user' => null, 'subject' => null, 'params' => []]);
            foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
                $n->method($setter)->willReturnSelf();
            }
            $n->method('setUser')->willReturnCallback(function (string $uid) use ($n, $state) { $state['user'] = $uid; return $n; });
            $n->method('setSubject')->willReturnCallback(function (string $s, array $p = []) use ($n, $state) { $state['subject'] = $s; $state['params'] = $p; return $n; });
            $n->method('getUser')->willReturnCallback(fn () => $state['user'] ?? '');
            $n->method('getSubject')->willReturnCallback(fn () => $state['subject'] ?? '');
            $n->method('getSubjectParameters')->willReturnCallback(fn () => $state['params']);
            return $n;
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n) {
            if ($n->getUser() === $this->failFor) {
                throw new \RuntimeException('broken account');
            }
            $this->sent[] = ['user' => $n->getUser(), 'subject' => $n->getSubject(), 'params' => $n->getSubjectParameters()];
        });
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n) {
            $this->dismissed[] = ['user' => $n->getUser() ?: null, 'subject' => $n->getSubject() ?: null];
        });

        $course = new Course();
        $course->setId(7);
        $course->setTitle('Arbeitsschutz');
        $course->setInstructorId('owner');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        $members = [];
        foreach (['co' => 'instructor', 'learner' => 'student', 'other' => 'student'] as $uid => $role) {
            $m = new CourseMember();
            $m->setUserId($uid);
            $m->setRole($role);
            $members[] = $m;
        }
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn($members);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));

        return new SupportTicketNotifier($manager, $courses, $memberMapper, $time, $this->createMock(LoggerInterface::class));
    }

    /** @return array{0: SupportTicketService, 1: SupportTicketMapper} */
    private function service(bool $viewerIsInstructor = false, bool $member = true, ?SupportTicketNotifier $notifier = null): array {
        $mapper = $this->createMock(SupportTicketMapper::class);
        $mapper->method('insert')->willReturnCallback(static function (SupportTicket $t): SupportTicket { $t->setId(11); return $t; });
        $mapper->method('update')->willReturnArgument(0);
        $courses = $this->createMock(CourseService::class);
        if ($member) {
            $courses->method('findById')->willReturn(['id' => 7, 'title' => 'Arbeitsschutz', 'is_instructor' => $viewerIsInstructor]);
        } else {
            $courses->method('findById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));
        }
        $courses->method('canManageCourse')->willReturn(true);
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('isAvailable')->willReturn(false);
        $service = new SupportTicketService($mapper, $courses, $gemini, $this->createMock(TelosService::class), $notifier ?? $this->notifier());
        return [$service, $mapper];
    }

    // ── asking ────────────────────────────────────────────────────────────────

    public function testALearnerAsksTheInstructorsOfTheCourse(): void {
        [$service, $mapper] = $this->service();
        $inserted = null;
        $mapper->expects($this->once())->method('insert')->willReturnCallback(function (SupportTicket $t) use (&$inserted) {
            $t->setId(11);
            $inserted = $t;
            return $t;
        });

        $question = $service->askCourseQuestion(7, 'learner', 'Frist', 'Bis wann gilt die Schulung?');

        $this->assertSame('course_instructor', $inserted->getRoutingTargetType());
        $this->assertSame(7, $inserted->getRoutingCourseId());
        $this->assertSame('course_content', $inserted->getCategory());
        $this->assertSame('learner', $inserted->getUserId());
        $this->assertSame(['id', 'subject', 'message', 'status', 'answer_text', 'answered_at', 'created_at'], array_keys($question));
    }

    public function testAnInstructorOrAnOutsiderCannotAsk(): void {
        [$instructorService, $mapper] = $this->service(true);
        $mapper->expects($this->never())->method('insert');
        try {
            $instructorService->askCourseQuestion(7, 'owner', 'x', 'y');
            $this->fail('an instructor (also in the student view) must not ask');
        } catch (ForbiddenException $e) {
        }

        [$outsiderService] = $this->service(false, false);
        $this->expectException(ForbiddenException::class);
        $outsiderService->askCourseQuestion(7, 'stranger', 'x', 'y');
    }

    public function testAnEmptyQuestionIsRefused(): void {
        [$service] = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $service->askCourseQuestion(7, 'learner', 'x', '   ');
    }

    public function testTheLearnerReadsOnlyTheirOwnQuestionsWithoutTriageFields(): void {
        [$service, $mapper] = $this->service();
        $mapper->expects($this->once())->method('findByUserAndRoutingCourse')->with('learner', 7, 50)
            ->willReturn([$this->ticket(['status' => 'answered', 'answer' => 'Bis Freitag.'])]);

        $result = $service->listMyCourseQuestions(7, 'learner');

        $this->assertTrue($result['can_ask']);
        $this->assertSame('Bis Freitag.', $result['questions'][0]['answer_text']);
        foreach (['ai_label', 'ai_draft_answer', 'answered_by', 'needs_review', 'user_id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $result['questions'][0]);
        }
    }

    public function testAnInstructorInTheStudentViewMayReadButNotAsk(): void {
        [$service, $mapper] = $this->service(true);
        $mapper->method('findByUserAndRoutingCourse')->willReturn([]);
        $this->assertFalse($service->listMyCourseQuestions(7, 'owner')['can_ask']);
    }

    public function testAnOutsiderCannotListQuestions(): void {
        [$service, $mapper] = $this->service(false, false);
        $mapper->expects($this->never())->method('findByUserAndRoutingCourse');
        $this->expectException(ForbiddenException::class);
        $service->listMyCourseQuestions(7, 'stranger');
    }

    // ── notifications ───────────────────────────────────────────────────────────

    public function testANewQuestionNotifiesTheOwnerAndCoInstructorsButNotLearners(): void {
        [$service] = $this->service();
        $service->askCourseQuestion(7, 'learner', 'Frist', 'Bis wann?');

        $this->assertSame(['owner', 'co'], array_column($this->sent, 'user'));
        $this->assertSame('ticket_created', $this->sent[0]['subject']);
        $this->assertSame(['course_id' => 7, 'course_title' => 'Arbeitsschutz'], $this->sent[0]['params']);
    }

    public function testAQuestionForTheAdminInboxNotifiesNoInstructor(): void {
        $this->notifier()->questionAsked($this->ticket(['routing' => 'admin', 'course' => null]));
        $this->assertSame([], $this->sent);
    }

    public function testOneBrokenRecipientDoesNotCostTheOthersTheirNotification(): void {
        $this->failFor = 'owner';
        $this->notifier()->questionAsked($this->ticket());
        $this->assertSame(['co'], array_column($this->sent, 'user'));
    }

    public function testTheFirstAnswerNotifiesTheLearner(): void {
        [$service, $mapper] = $this->service();
        $mapper->method('findById')->willReturn($this->ticket());

        $service->answer(11, 'Bis Freitag.', 'owner', false, 7);

        $this->assertSame([['user' => 'learner', 'subject' => 'ticket_answered', 'params' => ['course_id' => 7, 'ticket_subject' => 'Frist']]], $this->sent);
        $this->assertContains(['user' => null, 'subject' => 'ticket_created'], $this->dismissed, 'the instructors\' new-question notice is settled');
    }

    public function testAChangedAnswerNotifiesTheLearnerAgain(): void {
        [$service, $mapper] = $this->service();
        $mapper->method('findById')->willReturn($this->ticket(['status' => 'answered', 'answer' => 'Bis Freitag.']));

        $service->answer(11, 'Bis Montag.', 'owner', true);

        $this->assertSame('ticket_answer_updated', $this->sent[0]['subject']);
        $this->assertContains(['user' => 'learner', 'subject' => null], $this->dismissed, 'the older answer notice is replaced');
    }

    public function testSavingTheSameAnswerAgainNotifiesNobody(): void {
        [$service, $mapper] = $this->service();
        $mapper->method('findById')->willReturn($this->ticket(['status' => 'answered', 'answer' => 'Bis Freitag.']));

        $service->answer(11, '  Bis Freitag. ', 'owner', true);

        $this->assertSame([], $this->sent);
    }

    public function testApprovingAnAiDraftNotifiesTheLearner(): void {
        [$service, $mapper] = $this->service();
        $mapper->method('findById')->willReturn($this->ticket(['routing' => 'admin', 'course' => null]));

        $service->approveDraft(11, 'admin');

        $this->assertSame('ticket_answered', $this->sent[0]['subject']);
        $this->assertSame(0, $this->sent[0]['params']['course_id'], 'an admin ticket links to the app, not a course');
    }

    // ── rendering and endpoint ────────────────────────────────────────────────

    /** @return array{subject: string, link: string} */
    private function prepare(string $subject, array $params): array {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, $p = []) => vsprintf($text, (array)$p));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning/');
        $urls->method('getAbsoluteURL')->willReturn('https://nc.example/icon.svg');
        $urls->method('imagePath')->willReturn('/icon.svg');

        $out = new \ArrayObject();
        $n = $this->createMock(INotification::class);
        $n->method('getApp')->willReturn('learning');
        $n->method('getSubject')->willReturn($subject);
        $n->method('getSubjectParameters')->willReturn($params);
        $n->method('setParsedSubject')->willReturnCallback(function (string $s) use ($n, $out) { $out['subject'] = $s; return $n; });
        $n->method('setLink')->willReturnCallback(function (string $s) use ($n, $out) { $out['link'] = $s; return $n; });
        $n->method('setIcon')->willReturnSelf();

        (new Notifier($factory, $urls))->prepare($n, 'de');
        return ['subject' => $out['subject'], 'link' => $out['link']];
    }

    public function testTheNotificationsOpenTheRightCourseTab(): void {
        $this->assertSame(
            ['subject' => 'Neue Frage von Lernenden im Kurs Arbeitsschutz', 'link' => 'https://nc.example/apps/learning/courses/7/requests'],
            $this->prepare('ticket_created', ['course_id' => 7, 'course_title' => 'Arbeitsschutz'])
        );
        $this->assertSame(
            ['subject' => 'Deine Frage „Frist“ wurde beantwortet', 'link' => 'https://nc.example/apps/learning/courses/7/my-questions'],
            $this->prepare('ticket_answered', ['course_id' => 7, 'ticket_subject' => 'Frist'])
        );
        $this->assertSame('Die Antwort auf deine Frage „Frist“ wurde geändert', $this->prepare('ticket_answer_updated', ['course_id' => 0, 'ticket_subject' => 'Frist'])['subject']);
        $this->assertSame('https://nc.example/apps/learning/', $this->prepare('ticket_answer_updated', ['course_id' => 0, 'ticket_subject' => 'Frist'])['link']);
    }

    public function testTheEndpointAnswers403ForAnInstructorAnd201ForALearner(): void {
        [$instructorService] = $this->service(true);
        $controller = new SupportTicketController('learning', $this->createMock(IRequest::class), $instructorService,
            $this->createMock(CourseService::class), $this->createMock(IGroupManager::class), 'owner');
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->askCourse(7, 'x', 'y')->getStatus());

        [$learnerService] = $this->service();
        $controller = new SupportTicketController('learning', $this->createMock(IRequest::class), $learnerService,
            $this->createMock(CourseService::class), $this->createMock(IGroupManager::class), 'learner');
        $this->assertSame(Http::STATUS_CREATED, $controller->askCourse(7, 'Frist', 'Bis wann?')->getStatus());
        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->askCourse(7, 'Frist', '')->getStatus());
    }
}
