<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Notification\Notifier;
use OCA\Learning\Service\SupportTicketNotifier;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TechnicalTicketRecipients;
use OCP\AppFramework\App;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #59 (admin side), against a real Nextcloud: the container wires the chosen recipients
 * into the notifier (its constructor arguments are optional, so a wiring gap would notify
 * nobody without an error), a technical ticket reaches exactly the chosen administrator among
 * real accounts and groups, and a deleted account leaves the list through the registered
 * listener. Also the SQL of the open
 * count of the admin inbox.
 */
final class TechnicalTicketNotify59Test extends TestCase {
    private const ADMIN = 'it59-ops-admin';
    private const OTHER_ADMIN = 'it59-other-admin';
    private const AUTHOR = 'it59-author';

    private ?string $savedValue = null;

    protected function setUp(): void {
        $value = Server::get(IConfig::class)->getAppValue('learning', TechnicalTicketRecipients::KEY, "\0absent");
        $this->savedValue = $value === "\0absent" ? null : $value;
        $this->clean();
        $users = Server::get(IUserManager::class);
        $admins = Server::get(IGroupManager::class)->get('admin');
        $this->assertNotNull($admins, 'the admin group exists');
        foreach ([self::ADMIN, self::OTHER_ADMIN, self::AUTHOR] as $uid) {
            $user = $users->createUser($uid, 'It59-' . bin2hex(random_bytes(8)) . '!Aa');
            $this->assertNotFalse($user);
            if ($uid !== self::AUTHOR) {
                $admins->addUser($user);
            }
        }
    }

    protected function tearDown(): void {
        $this->clean();
        $config = Server::get(IConfig::class);
        if ($this->savedValue === null) {
            $config->deleteAppValue('learning', TechnicalTicketRecipients::KEY);
        } else {
            $config->setAppValue('learning', TechnicalTicketRecipients::KEY, $this->savedValue);
        }
    }

    private function clean(): void {
        $users = Server::get(IUserManager::class);
        foreach ([self::ADMIN, self::OTHER_ADMIN, self::AUTHOR] as $uid) {
            $users->get($uid)?->delete();
        }
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->delete('learning_support_tickets')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::AUTHOR)))
            ->executeStatement();
    }

    public function testTheContainerWiresTheRecipientsIntoTheNotifier(): void {
        // the notifier's #59 arguments are optional: a wiring gap would notify nobody, silently
        $container = (new App('learning'))->getContainer();
        $notifier = $container->get(SupportTicketNotifier::class);
        $reflection = new \ReflectionClass($notifier);
        foreach (['technicalRecipients', 'userManager', 'groupManager'] as $property) {
            $this->assertNotNull($reflection->getProperty($property)->getValue($notifier), $property);
        }
        $service = $container->get(SupportTicketService::class);
        $this->assertNotNull((new \ReflectionClass($service))->getProperty('notifier')->getValue($service), 'the service has the notifier');
        $this->assertNotNull((new \ReflectionClass($service))->getProperty('auditService')->getValue($service), 'the service has the audit log');
    }

    public function testATechnicalTicketReachesTheChosenAdministratorOnly(): void {
        // real accounts, groups and stored list; only the delivery is recorded (a CLI test run
        // stores no notification rows)
        $sent = new \ArrayObject();
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(fn () => Server::get(INotificationManager::class)->createNotification());
        $manager->method('notify')->willReturnCallback(function (INotification $n) use ($sent): void {
            $sent[] = [$n->getUser(), $n->getSubject()];
        });
        $recipients = (new App('learning'))->getContainer()->get(TechnicalTicketRecipients::class);
        $recipients->save([self::ADMIN, self::AUTHOR, 'it59-never-existed']);
        $notifier = new SupportTicketNotifier($manager, Server::get(CourseMapper::class), Server::get(CourseMemberMapper::class),
            Server::get(ITimeFactory::class), Server::get(LoggerInterface::class), $recipients,
            Server::get(IUserManager::class), Server::get(IGroupManager::class));

        $ticket = new SupportTicket();
        $ticket->setId(990591);
        $ticket->setUserId('it59-learner');
        $ticket->setSubject('Login geht nicht');
        $ticket->setRoutingTargetType('admin');
        $notifier->questionAsked($ticket);

        // the author is no administrator, the third account does not exist; OTHER_ADMIN was not chosen
        $this->assertSame([[self::ADMIN, 'technical_ticket_created']], $sent->getArrayCopy());
    }

    public function testTheNoticeRendersWithTheRealAdminSettingsRoute(): void {
        // every unit test mocks the URL generator; a wrong route name would throw in prepare()
        $n = Server::get(INotificationManager::class)->createNotification();
        $n->setApp('learning')->setUser(self::ADMIN)->setDateTime(new \DateTime())
            ->setObject('support_ticket', '990591')
            ->setSubject('technical_ticket_created', ['ticket_subject' => 'Login geht nicht']);

        $prepared = (new App('learning'))->getContainer()->get(Notifier::class)->prepare($n, 'en');

        $this->assertStringEndsWith('/settings/admin/learning', $prepared->getLink());
        $this->assertSame('New technical ticket: “Login geht nicht”', $prepared->getParsedSubject());
    }

    public function testADeletedAccountLeavesTheRecipientList(): void {
        $container = (new App('learning'))->getContainer();
        $recipients = $container->get(TechnicalTicketRecipients::class);
        $recipients->save([self::ADMIN, self::OTHER_ADMIN]);

        $this->assertTrue((bool)Server::get(IUserManager::class)->get(self::ADMIN)?->delete());

        $this->assertSame([self::OTHER_ADMIN], $recipients->get());
    }

    public function testTheOpenCountOfTheAdminInboxLeavesAnsweredAndArchivedOut(): void {
        $service = (new App('learning'))->getContainer()->get(SupportTicketService::class);
        $mapper = Server::get(SupportTicketMapper::class);
        $before = $mapper->countOpenByRoutingTarget('admin');

        $open = $service->create(self::AUTHOR, 'a', 'offen', [], 'technical');
        $archived = $service->create(self::AUTHOR, 'b', 'archiviert', [], 'technical');
        $service->create(self::AUTHOR, 'c', 'beantwortet', [], 'technical');
        $answered = $mapper->findByRoutingTarget('admin', 200);
        foreach ($answered as $t) {
            if ($t->getSubject() === 'c' && $t->getUserId() === self::AUTHOR) {
                $service->answer((int)$t->getId(), 'Erledigt.', self::OTHER_ADMIN, true);
            }
        }
        $service->setAdminTicketArchived((int)$archived->getId(), true);

        $this->assertSame($before + 1, $mapper->countOpenByRoutingTarget('admin'));
        $this->assertNotNull($open->getId());
    }
}
