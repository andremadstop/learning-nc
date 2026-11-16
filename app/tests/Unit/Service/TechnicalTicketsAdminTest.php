<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Controller\TicketRecipientsController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Notification\Notifier;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketNotifier;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TechnicalTicketRecipients;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #59 (admin side): nobody learned of a new technical ticket (the admin inbox), and the
 * inbox could only answer. The admin now picks who is notified ("Users to be notified of
 * technical tickets"; nobody chosen = nobody notified), and archives or deletes technical
 * tickets. Only administrators may do any of it.
 */
#[AllowMockObjectsWithoutExpectations]
class TechnicalTicketsAdminTest extends TestCase {
    /** @var list<array{user: string, subject: string, params: array}> */
    private array $sent = [];
    /** @var list<array{user: ?string, subject: ?string}> */
    private array $dismissed = [];
    /** @var array<string, string> app values */
    private array $appValues = [];
    /** @var array<int, SupportTicket> */
    private array $tickets = [];
    /** @var list<int> */
    private array $deleted = [];
    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $audit = [];

    /** uid => [exists, enabled, admin] */
    private const ACCOUNTS = [
        'ops' => [true, true, true],
        'second' => [true, true, true],
        'author' => [true, true, true],
        'disabled' => [true, false, true],
        'plain' => [true, true, false],
        'gone' => [false, false, false],
    ];

    private function config(): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, $default = '') => $this->appValues[$key] ?? $default);
        $config->method('setAppValue')->willReturnCallback(function (string $app, string $key, $value): void {
            $this->appValues[$key] = (string)$value;
        });
        return $config;
    }

    private function recipients(): TechnicalTicketRecipients {
        return new TechnicalTicketRecipients($this->config(), 'learning');
    }

    private function userMgr(): IUserManager {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            [$exists, $enabled] = self::ACCOUNTS[$uid] ?? [false, false, false];
            if (!$exists) {
                return null;
            }
            $user = $this->createMock(TechnicalTicketsAdminTestUser::class);
            $user->method('isEnabled')->willReturn($enabled);
            $user->method('getDisplayName')->willReturn(ucfirst($uid));
            return $user;
        });
        return $users;
    }

    private function groupMgr(): IGroupManager {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => (self::ACCOUNTS[$uid] ?? [false, false, false])[2]);
        $groups->method('get')->willReturnCallback(function (string $gid): ?IGroup {
            if ($gid !== 'admin') {
                return null;
            }
            $ops = $this->createMock(IUser::class);
            $ops->method('getUID')->willReturn('ops');
            $ops->method('getDisplayName')->willReturn('Ops Team');
            $group = $this->createMock(IGroup::class);
            $group->method('searchUsers')->willReturn([$ops]);
            return $group;
        });
        return $groups;
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
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->sent[] = ['user' => $n->getUser(), 'subject' => $n->getSubject(), 'params' => $n->getSubjectParameters()];
        });
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->dismissed[] = ['user' => $n->getUser() ?: null, 'subject' => $n->getSubject() ?: null];
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));
        return new SupportTicketNotifier($manager, $this->createMock(CourseMapper::class), $this->createMock(CourseMemberMapper::class),
            $time, $this->createMock(LoggerInterface::class), $this->recipients(), $this->userMgr(), $this->groupMgr());
    }

    private function ticket(int $id, array $fields = []): SupportTicket {
        $ticket = new SupportTicket();
        $ticket->setId($id);
        $ticket->setUserId($fields['user'] ?? 'learner');
        $ticket->setSubject($fields['subject'] ?? 'Login geht nicht');
        $ticket->setMessage('Seit heute kein Zugang, Passwort geheim123');
        $ticket->setStatus($fields['status'] ?? 'open');
        $ticket->setCategory('technical');
        $ticket->setCreatedAt(100);
        $ticket->setRoutingTargetType($fields['routing'] ?? 'admin');
        $ticket->setRoutingCourseId($fields['course'] ?? null);
        $ticket->setAnswerText($fields['answer'] ?? null);
        $this->tickets[$id] = $ticket;
        return $ticket;
    }

    private function service(): SupportTicketService {
        $mapper = $this->createMock(SupportTicketMapper::class);
        $mapper->method('findById')->willReturnCallback(function (int $id): SupportTicket {
            if (!isset($this->tickets[$id])) {
                throw new DoesNotExistException('none');
            }
            return $this->tickets[$id];
        });
        $mapper->method('insert')->willReturnCallback(static function (SupportTicket $t): SupportTicket { $t->setId(21); return $t; });
        $mapper->method('update')->willReturnArgument(0);
        $mapper->method('delete')->willReturnCallback(function (SupportTicket $t): SupportTicket {
            $this->deleted[] = (int)$t->getId();
            unset($this->tickets[(int)$t->getId()]);
            return $t;
        });
        $mapper->method('restoreArchived')->willReturnCallback(function (int $id): void {
            $t = $this->tickets[$id] ?? null;
            if ($t !== null && $t->getStatus() === 'archived') {
                $t->setStatus(trim((string)$t->getAnswerText()) !== '' ? 'answered' : 'open');
            }
        });
        $mapper->method('findByRoutingTarget')->willReturnCallback(fn (string $target, int $limit, bool $archived): array => array_values(array_filter(
            $this->tickets,
            static fn (SupportTicket $t): bool => $t->getRoutingTargetType() === $target && ($t->getStatus() === 'archived') === $archived
        )));
        $mapper->method('countOpenByRoutingTarget')->willReturn(4);
        $audit = $this->createMock(AuditService::class);
        $audit->method('logEvent')->willReturnCallback(function (string $key, string $uid, array $context = []): void {
            $this->audit[] = [$key, $uid, $context];
        });
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('isAvailable')->willReturn(false);
        return new SupportTicketService($mapper, $this->createMock(CourseService::class), $gemini,
            $this->createMock(TelosService::class), $this->notifier(), $audit);
    }

    private function controller(string $uid): SupportTicketController {
        return new SupportTicketController('learning', $this->createMock(IRequest::class), $this->service(),
            $this->createMock(CourseService::class), $this->groupMgr(), $uid);
    }

    // ── who is notified ─────────────────────────────────────────────────────────

    public function testANewTechnicalTicketGoesOnlyToTheChosenAdministrators(): void {
        $this->recipients()->save(['ops', 'second']);
        $this->service()->create('learner', 'Login geht nicht', 'Seit heute kein Zugang', [], 'technical');

        $this->assertSame(['ops', 'second'], array_column($this->sent, 'user'));
        $this->assertSame('technical_ticket_created', $this->sent[0]['subject']);
        $this->assertSame(['ticket_subject' => 'Login geht nicht'], $this->sent[0]['params'], 'the subject, never the message');
    }

    public function testNobodyChosenNotifiesNobody(): void {
        $this->service()->create('learner', 'Login geht nicht', 'Seit heute kein Zugang', [], 'technical');
        $this->assertSame([], $this->sent);

        $this->recipients()->save([]);
        $this->service()->create('learner', 'Login geht nicht', 'Seit heute kein Zugang', [], 'technical');
        $this->assertSame([], $this->sent, 'not "every administrator" either');
    }

    public function testDeletedDisabledNonAdminAndTheAuthorAreSkipped(): void {
        $this->recipients()->save(['gone', 'disabled', 'plain', 'author', 'ops']);
        $this->service()->create('author', 'Login geht nicht', 'Seit heute kein Zugang', [], 'technical');

        $this->assertSame(['ops'], array_column($this->sent, 'user'));
    }

    public function testACourseQuestionDoesNotReachTheTechnicalRecipients(): void {
        $this->recipients()->save(['ops']);
        $this->notifier()->questionAsked($this->ticket(5, ['routing' => 'course_instructor', 'course' => 7]));
        $this->assertNotContains('ops', array_column($this->sent, 'user'));
    }

    public function testAnAnswerOrTheArchiveSettlesTheAdminsNotice(): void {
        $this->ticket(9);
        $this->service()->answer(9, 'Bitte Cache leeren.', 'ops', true);
        $this->assertContains(['user' => null, 'subject' => 'technical_ticket_created'], $this->dismissed);

        $this->dismissed = [];
        $this->ticket(10);
        $this->service()->setAdminTicketArchived(10, true);
        $this->assertContains(['user' => null, 'subject' => 'technical_ticket_created'], $this->dismissed);
    }

    public function testTheNoticeNamesTheTicketAndOpensTheAdminInbox(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, $p = []) => vsprintf($text, (array)$p));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $p = []): string => $route === 'settings.AdminSettings.index'
            ? 'https://nc.example/settings/admin/' . $p['section']
            : 'https://nc.example/apps/learning/');
        $urls->method('getAbsoluteURL')->willReturn('https://nc.example/icon.svg');
        $urls->method('imagePath')->willReturn('/icon.svg');
        $out = new \ArrayObject();
        $n = $this->createMock(INotification::class);
        $n->method('getApp')->willReturn('learning');
        $n->method('getSubject')->willReturn('technical_ticket_created');
        $n->method('getSubjectParameters')->willReturn(['ticket_subject' => 'Login geht nicht']);
        $n->method('setParsedSubject')->willReturnCallback(function (string $s) use ($n, $out) { $out['subject'] = $s; return $n; });
        $n->method('setLink')->willReturnCallback(function (string $s) use ($n, $out) { $out['link'] = $s; return $n; });
        $n->method('setIcon')->willReturnSelf();

        (new Notifier($factory, $urls))->prepare($n, 'de');

        $this->assertSame('Neue technische Anfrage: „Login geht nicht“', $out['subject']);
        $this->assertSame('https://nc.example/settings/admin/learning', $out['link']);
    }

    // ── the stored list ─────────────────────────────────────────────────────────

    public function testTheListIsCleanedAndADamagedValueNotifiesNobody(): void {
        $this->assertSame(['ops', 'second'], $this->recipients()->save(['ops', ' ops ', '', 42, 'second', str_repeat('x', 65)]));
        $this->appValues[TechnicalTicketRecipients::KEY] = '{broken';
        $this->assertSame([], $this->recipients()->get());
    }

    public function testRemovingAnAccountThatIsNotListedWritesNothing(): void {
        $this->recipients()->save(['ops', 'second']);
        $this->assertTrue($this->recipients()->removeUser('ops'));
        $this->assertSame(['second'], $this->recipients()->get());

        $before = $this->appValues;
        $this->assertFalse($this->recipients()->removeUser('nobody'));
        $this->assertSame($before, $this->appValues, 'nothing to remove, nothing written');
    }

    // ── archive and delete ──────────────────────────────────────────────────────

    public function testAnAdministratorArchivesAndRestoresATechnicalTicket(): void {
        $this->ticket(9);
        $this->ticket(10, ['status' => 'answered', 'answer' => 'Erledigt.']);
        $controller = $this->controller('ops');

        $this->assertSame('archived', $controller->archiveAdminTicket(9)->getData()['ticket']['status']);
        $this->assertSame([9], array_column($controller->adminList(100, true)->getData()['tickets'], 'id'));
        $this->assertSame([10], array_column($controller->adminList()->getData()['tickets'], 'id'), 'the inbox leaves it out');
        $this->assertSame(4, $controller->adminList(100, true)->getData()['open_count'], 'the open count is the server\'s, also in the archive');

        $this->assertSame('open', $controller->restoreAdminTicket(9)->getData()['ticket']['status']);
        $controller->archiveAdminTicket(10);
        $this->assertSame('answered', $controller->restoreAdminTicket(10)->getData()['ticket']['status']);
    }

    public function testDeletingRemovesTheTicketItsNoticesAndLeavesAnAuditEventWithoutItsText(): void {
        $this->ticket(9);
        $response = $this->controller('ops')->deleteAdminTicket(9);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([9], $this->deleted);
        $this->assertContains(['user' => null, 'subject' => null], $this->dismissed, 'every notice about it goes, the learner\'s too');
        $this->assertCount(1, $this->audit);
        [$key, $uid, $context] = $this->audit[0];
        $this->assertSame('support_ticket_deleted', $key);
        $this->assertSame('ops', $uid);
        $this->assertSame(9, $context['ticket_id']);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Login', (string)$encoded);
        $this->assertStringNotContainsString('geheim123', (string)$encoded);
    }

    public function testACourseQuestionCannotBeReachedThroughTheAdminInbox(): void {
        $this->ticket(5, ['routing' => 'course_instructor', 'course' => 7]);
        $controller = $this->controller('ops');

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->archiveAdminTicket(5)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->restoreAdminTicket(5)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->deleteAdminTicket(5)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->deleteAdminTicket(404)->getStatus());
        $this->assertSame([], $this->deleted);
        $this->assertSame('open', $this->tickets[5]->getStatus());
    }

    // ── rights ──────────────────────────────────────────────────────────────────

    public function testANonAdministratorGets403Everywhere(): void {
        $this->ticket(9);
        $controller = $this->controller('plain');
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->adminList()->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->adminList(100, true)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->archiveAdminTicket(9)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->restoreAdminTicket(9)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->deleteAdminTicket(9)->getStatus());
        $this->assertSame([], $this->deleted);
        $this->assertSame('open', $this->tickets[9]->getStatus());

        $recipients = new TicketRecipientsController('learning', $this->createMock(IRequest::class), $this->recipients(), $this->groupMgr(), $this->userMgr(), 'plain');
        $this->assertSame(Http::STATUS_FORBIDDEN, $recipients->get()->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $recipients->save(['plain'])->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $recipients->search('o')->getStatus());
        $this->assertSame([], $this->appValues, 'nothing saved');
    }

    public function testTheRecipientEndpointsForAnAdministrator(): void {
        $controller = new TicketRecipientsController('learning', $this->createMock(IRequest::class), $this->recipients(), $this->groupMgr(), $this->userMgr(), 'ops');

        $this->assertSame(['users' => []], $controller->get()->getData());
        $this->assertSame(['users' => [['id' => 'ops', 'label' => 'Ops Team']]], $controller->search('op')->getData(), 'administrators only');
        $this->assertSame(['users' => []], $controller->search('  ')->getData());

        $saved = $controller->save(['ops', 'plain', 'gone'])->getData()['users'];
        $this->assertSame([
            ['id' => 'ops', 'label' => 'Ops', 'notified' => true],
            ['id' => 'plain', 'label' => 'Plain', 'notified' => false],
            ['id' => 'gone', 'label' => 'gone', 'notified' => false],
        ], $saved);
        $this->assertSame($saved, $controller->get()->getData()['users']);
    }
}

/**
 * The minimal IUser stub of the unit tests (tests/Support/PhpUnitStubs.php) has no isEnabled();
 * adding it there would break the hand-written IUser doubles. On a real Nextcloud IUser has it.
 */
interface TechnicalTicketsAdminTestUser extends IUser {
    public function isEnabled();
}
