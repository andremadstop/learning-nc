<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\SupportTicket;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * GitHub #59: Nextcloud notifications for learner questions. Nobody learned of a new question
 * (instructors had to open the tab) or of an answer (learners saw it only inside VirtuProf),
 * and a changed answer went unnoticed even there.
 *
 *  - ticket_created: a question routed to a course goes to the course owner and its
 *    co-instructors, never to the author.
 *  - ticket_answered / ticket_answer_updated: to the author, only when the answer text really
 *    changed. A newer one replaces the older notification of the same ticket.
 *
 * A notification never breaks the ticket itself: every failure is logged and swallowed, per
 * recipient, so one broken account does not cost the others their notification.
 */
class SupportTicketNotifier {
    public const OBJECT_TYPE = 'support_ticket';

    private INotificationManager $notificationManager;
    private CourseMapper $courseMapper;
    private CourseMemberMapper $memberMapper;
    private ITimeFactory $timeFactory;
    private LoggerInterface $logger;

    public function __construct(
        INotificationManager $notificationManager,
        CourseMapper $courseMapper,
        CourseMemberMapper $memberMapper,
        ITimeFactory $timeFactory,
        LoggerInterface $logger
    ) {
        $this->notificationManager = $notificationManager;
        $this->courseMapper = $courseMapper;
        $this->memberMapper = $memberMapper;
        $this->timeFactory = $timeFactory;
        $this->logger = $logger;
    }

    public function questionAsked(SupportTicket $ticket): void {
        $courseId = $ticket->getRoutingCourseId();
        if ($ticket->getRoutingTargetType() !== 'course_instructor' || $courseId === null) {
            return;
        }
        try {
            $course = $this->courseMapper->findById((int)$courseId);
            $recipients = [(string)$course->getInstructorId()];
            foreach ($this->memberMapper->findByCourse((int)$courseId) as $member) {
                if ($member->getRole() === 'instructor') {
                    $recipients[] = (string)$member->getUserId();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Learner question notification: course lookup failed', ['app' => 'learning', 'exception' => $e]);
            return;
        }

        $author = (string)$ticket->getUserId();
        $recipients = array_values(array_unique(array_filter(
            $recipients,
            static fn (string $uid): bool => $uid !== '' && $uid !== $author
        )));
        foreach ($recipients as $uid) {
            $this->send($uid, 'ticket_created', $ticket, [
                'course_id' => (int)$courseId,
                'course_title' => (string)$course->getTitle(),
            ]);
        }
    }

    /**
     * @param string|null $previousAnswer the answer text before this save
     */
    public function answerGiven(SupportTicket $ticket, ?string $previousAnswer, string $answeredBy): void {
        $author = (string)$ticket->getUserId();
        $previous = trim((string)$previousAnswer);
        $current = trim((string)$ticket->getAnswerText());
        if ($author === '' || $current === '' || $current === $previous) {
            return;
        }

        $ticketId = (string)$ticket->getId();
        // the instructors' "new question" notice is settled once the question has an answer
        $this->dismiss(null, 'ticket_created', $ticketId);
        if ($author === $answeredBy) {
            return;
        }
        // only the newest answer notice of a ticket stays in the learner's list
        $this->dismiss($author, null, $ticketId);
        $this->send($author, $previous === '' ? 'ticket_answered' : 'ticket_answer_updated', $ticket, [
            'course_id' => $ticket->getRoutingTargetType() === 'course_instructor' ? (int)$ticket->getRoutingCourseId() : 0,
            'ticket_subject' => mb_substr((string)$ticket->getSubject(), 0, 120),
        ]);
    }

    /** @param array<string, int|string> $params */
    private function send(string $uid, string $subject, SupportTicket $ticket, array $params): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('learning')
                ->setUser($uid)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject(self::OBJECT_TYPE, (string)$ticket->getId())
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Learner question notification failed', ['app' => 'learning', 'subject' => $subject, 'exception' => $e]);
        }
    }

    private function dismiss(?string $uid, ?string $subject, string $ticketId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('learning')->setObject(self::OBJECT_TYPE, $ticketId);
            if ($uid !== null) {
                $notification->setUser($uid);
            }
            if ($subject !== null) {
                $notification->setSubject($subject);
            }
            $this->notificationManager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Learner question notification could not be dismissed', ['app' => 'learning', 'exception' => $e]);
        }
    }
}
