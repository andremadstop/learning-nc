<?php
declare(strict_types=1);
namespace OCA\Learning\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\UnknownNotificationException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator,
        private ?\OCP\IUserManager $userManager = null,
    ) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'learning';
    }

    public function getName(): string {
        return $this->l10nFactory->get('learning')->t('Learning');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'learning') {
            throw new UnknownNotificationException('Wrong app');
        }

        $l = $this->l10nFactory->get('learning', $languageCode);
        $appUrl = $this->urlGenerator->linkToRouteAbsolute('learning.page.index');

        switch ($notification->getSubject()) {
            case 'badge_earned':
                $params = $notification->getSubjectParameters();
                $badgeName = $params['badge_name'] ?? '';
                $badgeEmoji = $params['badge_emoji'] ?? '';
                $notification->setParsedSubject(
                    $l->t('Achievement unlocked: %s %s', [$badgeEmoji, $badgeName])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'streak_warning':
                $params = $notification->getSubjectParameters();
                $days = $params['streak_days'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('Your %s-day streak expires today!', [(string)$days])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'due_cards':
            case 'due_reminder':
                $params = $notification->getSubjectParameters();
                $dueCount = $params['due_count'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('You have %s due cards. Time to study!', [(string)$dueCount])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'exam_reminder':
                $params = $notification->getSubjectParameters();
                $days = max(1, (int)($params['days'] ?? 0));
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->n('Exam in %n day: %s', 'Exam in %n days: %s', $days, [$courseTitle])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'certificate_issued':
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->t('Certificate issued: %s', [$courseTitle])
                );
                // GitHub #35: open the course's completion tab, where the certificate is. The
                // router serves /courses/<id>/<tab>; notifications from before course_id was
                // stored keep linking to the app root.
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId . '/summary'
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'certificate_revoked':
            case 'certification_cycle_opened':
                // GitHub #84: the learner learns of the revoke, and of the new cycle the instructor
                // opened afterwards; both open the course's completion tab.
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject($notification->getSubject() === 'certificate_revoked'
                    ? $l->t('Dein Zertifikat für den Kurs %s wurde widerrufen', [$courseTitle])
                    : $l->t('Im Kurs %s ist ein neuer Zertifizierungszyklus offen', [$courseTitle]));
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId . '/summary'
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'exam_attempts_exhausted':
                // GitHub #87: to the course's instructors; the learner's name is read now, so a
                // renamed account shows its current name and a deleted one none.
                $params = $notification->getSubjectParameters();
                $learner = (string)($params['learner'] ?? '');
                $user = ($learner !== '' && $this->userManager !== null) ? $this->userManager->get($learner) : null;
                $name = $user !== null ? $user->getDisplayName() : $l->t('Eine lernende Person');
                $notification->setParsedSubject(
                    $l->t('%1$s hat alle Prüfungsversuche für „%2$s“ im Kurs %3$s aufgebraucht', [$name, (string)($params['pool_title'] ?? ''), (string)($params['course_title'] ?? '')])
                );
                $notification->setParsedMessage(
                    $l->t('Du kannst in der Mitgliederliste einen neuen Zertifizierungszyklus öffnen. Die bisherigen Versuche bleiben als Nachweis erhalten.')
                );
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId . '/members'
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'course_enrolled':
                // GitHub #35: an instructor enrolled this person. Without it the course just
                // appeared in the list one day, if the learner ever looked.
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->t('You were enrolled in the course %s', [$courseTitle])
                );
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'compliance_reminder':
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->t('Compliance reminder: %s', [$courseTitle])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'recert_reminder':
                // RECERT-06: T-30/T-7 cert-expiry reminder. msgids are the canonical l10n keys
                // from RecertL10n.test.js — 164-07 fills all 5 languages (incl. en).
                // recert_reminder_subject params: %1$s = days left, %2$s = course title.
                // recert_reminder_body    params: %1$s = course title, %2$s = expiry date.
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $daysLeft = (string)(int)($params['days_left'] ?? 0);
                $expiresAt = $params['expires_at'] ?? null;
                $expiresDate = is_numeric($expiresAt) ? date('Y-m-d', (int)$expiresAt) : '';
                $notification->setParsedSubject(
                    $l->t('recert_reminder_subject', [$daysLeft, $courseTitle])
                );
                $notification->setParsedMessage(
                    $l->t('recert_reminder_body', [$courseTitle, $expiresDate])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'ticket_created':
                // GitHub #59: a learner asked the instructors of a course a question
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->t('Neue Frage von Lernenden im Kurs %s', [$courseTitle])
                );
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId . '/requests'
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'technical_ticket_created':
                // GitHub #59: a technical ticket reached the admin inbox; to the chosen admins
                $params = $notification->getSubjectParameters();
                $notification->setParsedSubject(
                    $l->t('Neue technische Anfrage: „%s“', [(string)($params['ticket_subject'] ?? '')])
                );
                // the inbox sits at the top of the app's admin settings section
                $notification->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'learning']));
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            case 'ticket_answered':
            case 'ticket_answer_updated':
                // GitHub #59: the learner's question got an answer, or its answer was changed
                $params = $notification->getSubjectParameters();
                $ticketSubject = (string)($params['ticket_subject'] ?? '');
                $notification->setParsedSubject($notification->getSubject() === 'ticket_answered'
                    ? $l->t('Deine Frage „%s“ wurde beantwortet', [$ticketSubject])
                    : $l->t('Die Antwort auf deine Frage „%s“ wurde geändert', [$ticketSubject]));
                // a course question opens the learner's list of questions in that course
                $courseId = (int)($params['course_id'] ?? 0);
                $notification->setLink($courseId > 0
                    ? rtrim($appUrl, '/') . '/courses/' . $courseId . '/my-questions'
                    : $appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));
                break;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }

        return $notification;
    }
}
