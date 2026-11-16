<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;

class SupportTicketService {
    /**
     * The categories clients send: the ticket form (VirtuProfBubble: technical, course_content)
     * and VirtuProf's 'report this question' (question_error). Anything else is filed as technical.
     */
    private const CATEGORIES = ['technical', 'course_content', 'question_error'];

    private SupportTicketMapper $ticketMapper;
    private CourseService $courseService;
    private GeminiService $geminiService;
    private TelosService $telosService;
    private ?SupportTicketNotifier $notifier;

    public function __construct(
        SupportTicketMapper $ticketMapper,
        CourseService $courseService,
        GeminiService $geminiService,
        TelosService $telosService,
        ?SupportTicketNotifier $notifier = null,
        private ?AuditService $auditService = null,
    ) {
        $this->ticketMapper = $ticketMapper;
        $this->courseService = $courseService;
        $this->geminiService = $geminiService;
        $this->telosService = $telosService;
        $this->notifier = $notifier;
    }

    /**
     * GitHub #59: a learner asks the instructors of a course from its communication tab, without
     * VirtuProf. Only a learner of the course may (an instructor, also in the student view, gets
     * ForbiddenException); the question takes the same path as VirtuProf's course questions and
     * lands in "Questions from learners".
     *
     * @return array<string, mixed> the question as the learner sees it
     * @throws ForbiddenException when the user is no learner of the course
     * @throws \InvalidArgumentException for an empty or too long text
     */
    public function askCourseQuestion(int $courseId, string $userId, ?string $subject, string $message): array {
        $course = $this->learnerCourse($courseId, $userId);
        if (empty($course['can_ask'])) {
            throw new ForbiddenException('Only learners of this course can ask its instructors');
        }
        $ticket = $this->create($userId, $subject, $message, [
            'courseId' => $courseId,
            'courseTitle' => (string)($course['title'] ?? ''),
        ], 'course_content');
        return $this->serializeForLearner($ticket);
    }

    /**
     * GitHub #59: the caller's own questions to the instructors of a course, and whether the
     * caller may ask there.
     *
     * @return array{questions: list<array<string, mixed>>, can_ask: bool}
     * @throws ForbiddenException when the user cannot see the course
     */
    public function listMyCourseQuestions(int $courseId, string $userId): array {
        $course = $this->learnerCourse($courseId, $userId);
        return [
            'questions' => array_map([$this, 'serializeForLearner'], $this->ticketMapper->findByUserAndRoutingCourse($userId, $courseId, 50)),
            'can_ask' => (bool)$course['can_ask'],
        ];
    }

    /**
     * @return array<string, mixed> the course, with can_ask = the user is a learner of it
     * @throws ForbiddenException when the user is no member of the course
     */
    private function learnerCourse(int $courseId, string $userId): array {
        try {
            $course = $this->courseService->findById($courseId, $userId);
        } catch (\Throwable $e) {
            throw new ForbiddenException('No access to this course');
        }
        $course['can_ask'] = empty($course['is_instructor']);
        return $course;
    }

    public function create(string $userId, ?string $subject, string $message, array $context = [], ?string $category = null): SupportTicket {
        $subject = trim((string)$subject);
        $message = trim($message);
        // Audit #84/#145: a course the author cannot see is dropped from the ticket, so a
        // course_content ticket cannot be routed into a foreign course's instructor inbox.
        if ($this->normalizeInt($context['courseId'] ?? null) !== null
            && !$this->canSeeCourse((int)$context['courseId'], $userId)) {
            unset($context['courseId']);
        }
        if ($subject === '') {
            // audit #84: a subject the user never typed must not fail as 'Subject too long'
            $subject = mb_substr($this->buildFallbackSubject($context), 0, 255);
        }
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required');
        }
        if (mb_strlen($subject) > 255) {
            throw new \InvalidArgumentException('Subject too long');
        }
        if (mb_strlen($message) > 5000) {
            throw new \InvalidArgumentException('Message too long');
        }

        $now = time();
        $ticket = new SupportTicket();
        $ticket->setUserId($userId);
        $ticket->setStatus('open');
        $ticket->setSubject($subject);
        $ticket->setMessage($message);
        $ticket->setContextJson(json_encode($context));
        $ticket->setCourseId($this->normalizeInt($context['courseId'] ?? null));
        $ticket->setPoolId($this->normalizeInt($context['poolId'] ?? null));
        $ticket->setQuestionId($this->normalizeInt($context['questionId'] ?? null));
        $ticket->setDuelCode($this->normalizeString($context['duelCode'] ?? null, 32));
        $ticket->setLeagueSeasonId($this->normalizeInt($context['leagueSeasonId'] ?? null));
        $ticket->setCreatedAt($now);
        $ticket->setUpdatedAt($now);
        $ticket->setAnsweredBy(null);
        $ticket->setAnsweredAt(null);
        $ticket->setAnswerText(null);

        $routing = $this->resolveRouting($category, $ticket->getCourseId());
        $ticket->setCategory($routing['category']);
        $ticket->setRoutingTargetType($routing['routing_target_type']);
        $ticket->setRoutingCourseId($routing['routing_course_id']);

        /** @var SupportTicket $ticket */
        $ticket = $this->ticketMapper->insert($ticket);

        // GitHub #59: the course's instructors learn of a new question without opening the tab
        $this->notifier?->questionAsked($ticket);

        // AI Triage: classify ticket if AI is enabled AND the user consented to AI use.
        // AUDIT v5.2.1 (HIGH-03 consistency, pre-live review R4): the ticket free-text is sent to
        // Gemini for triage. Unlike the other LLM paths this must NOT block ticket creation (the
        // user needs help) — we simply skip the AI triage when consent is absent; the ticket is
        // still filed and handled by a human.
        // 5.7.0: "AI on" is the connection snapshot (GeminiService::isAvailable()), not the 5.5 key
        // ai_enabled, which an admin enabling AI through ai_connection never sets. A consent the
        // gateway refuses later (LlmConsentException) is swallowed by applyAiTriage(): no triage.
        if ($this->geminiService->isAvailable()
            && $this->telosService->hasAiConsent($userId)) {
            $this->applyAiTriage($ticket, $subject, $message, $userId);
        }

        return $ticket;
    }

    /**
     * Run AI triage classification and persist results.
     * Called after initial ticket insert — updates the ticket record with AI fields.
     * Sent with the ticket author's consent (spec 2.4); without it triage is skipped.
     */
    private function applyAiTriage(SupportTicket $ticket, string $subject, string $message, string $authorId): void {
        try {
            $result = $this->geminiService->classifyTicket($subject, $message, $authorId);

            $ticket->setAiLabel($result['label']);
            $ticket->setAiConfidence($result['confidence']);
            $ticket->setUpdatedAt(time());

            if ($result['label'] === 'FAQ' && $result['confidence'] >= 0.8 && $result['suggested_answer'] !== null) {
                $ticket->setAiDraftAnswer($result['suggested_answer']);
            }

            if (in_array($result['label'], ['Bug', 'Feature'], true)) {
                $ticket->setNeedsReview(true);
            }

            if ($result['followup_question'] !== null) {
                $ticket->setAiFollowupQuestion($result['followup_question']);
            }

            $this->ticketMapper->update($ticket);
        } catch (\Throwable $e) {
            // Triage failure must never break ticket creation
            // The ticket is already persisted — just log the error
        }
    }

    public function listMine(string $userId, int $limit = 50): array {
        // GitHub #59: VirtuProf's "My tickets" is the learner's view too; an archive is the staff's
        return array_map(function (SupportTicket $ticket): array {
            $data = $this->serializeTicket($ticket);
            $data['status'] = $this->learnerStatus($ticket);
            return $data;
        }, $this->ticketMapper->findByUser($userId, max(1, min(100, $limit))));
    }

    public function answer(int $ticketId, string $answerText, string $answeredBy, bool $isAdmin = false, ?int $instructorCourseId = null): array {
        $answerText = trim($answerText);
        if ($answerText === '') {
            throw new \InvalidArgumentException('Answer text is required');
        }
        if (mb_strlen($answerText) > 5000) {
            throw new \InvalidArgumentException('Answer text too long');
        }

        $ticket = $this->ticketMapper->findById($ticketId);

        if (!$isAdmin) {
            if ($ticket->getRoutingTargetType() === 'course_instructor' && $instructorCourseId !== null && $instructorCourseId === $ticket->getRoutingCourseId()) {
                // allowed
            } else {
                throw new ForbiddenException('Not authorized to answer this ticket');
            }
        }

        $previousAnswer = $ticket->getAnswerText();
        $ticket->setStatus('answered');
        $ticket->setAnswerText($answerText);
        $ticket->setAnsweredBy($answeredBy);
        $ticket->setAnsweredAt(time());
        $ticket->setUpdatedAt(time());

        // GitHub #59: answering an archived question brings it back to the inbox as answered, on
        // purpose: whoever answers it treats it as not settled
        $u = $this->updateAndReread($ticket);
        // GitHub #59: the learner learns of an answer, and of a changed one
        $this->notifier?->answerGiven($u, $previousAnswer, $answeredBy);
        return $this->serializeTicket($u);
    }

    /**
     * Admin one-click approve: copy ai_draft_answer → answer_text and mark ticket as answered.
     *
     * @throws \InvalidArgumentException if ticket has no draft answer
     * @throws \OCP\AppFramework\Db\DoesNotExistException if ticket not found
     */
    public function approveDraft(int $ticketId, string $answeredBy): array {
        $ticket = $this->ticketMapper->findById($ticketId);

        if ($ticket->getAiDraftAnswer() === null || trim((string)$ticket->getAiDraftAnswer()) === '') {
            throw new \InvalidArgumentException('Ticket has no AI draft answer to approve');
        }

        $previousAnswer = $ticket->getAnswerText();
        $ticket->setStatus('answered');
        $ticket->setAnswerText($ticket->getAiDraftAnswer());
        $ticket->setAnsweredBy($answeredBy);
        $ticket->setAnsweredAt(time());
        $ticket->setUpdatedAt(time());

        $u = $this->updateAndReread($ticket);
        $this->notifier?->answerGiven($u, $previousAnswer, $answeredBy);
        return $this->serializeTicket($u);
    }

    /**
     * GitHub #59: QBMapper::update() reports success for a row that is gone. A question withdrawn
     * or deleted between the read and the UPDATE is not found, and nobody is notified about it.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException when the row no longer exists
     */
    private function updateAndReread(SupportTicket $ticket): SupportTicket {
        $this->ticketMapper->update($ticket);
        return $this->ticketMapper->findById((int)$ticket->getId());
    }

    /**
     * GitHub #59: the admin inbox leaves archived technical tickets out; $archived = true lists
     * only those.
     */
    public function listAdmin(int $limit = 100, bool $archived = false): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByRoutingTarget('admin', max(1, min(200, $limit)), $archived));
    }

    /**
     * GitHub #59: unanswered, not archived technical tickets (the count at the admin inbox).
     */
    public function countOpenAdmin(): int {
        return $this->ticketMapper->countOpenByRoutingTarget('admin');
    }

    /**
     * GitHub #59: an administrator hides a technical ticket from the inbox (true) or brings it
     * back (false), as instructors do with the questions of their course. The caller checks that
     * the user is an administrator.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException when it is no technical ticket
     */
    public function setAdminTicketArchived(int $ticketId, bool $archived): array {
        $ticket = $this->adminTicket($ticketId);
        if ($archived) {
            $ticket->setStatus('archived');
            $ticket->setUpdatedAt(time());
            $u = $this->updateAndReread($ticket);
            $this->notifier?->questionSettled($u);
        } else {
            $this->ticketMapper->restoreArchived($ticketId, time());
            $u = $this->ticketMapper->findById($ticketId);
        }
        return $this->serializeTicket($u);
    }

    /**
     * GitHub #59: an administrator deletes a technical ticket for good, with its answer and every
     * notification about it. The audit trail keeps who deleted which ticket, never its text.
     * The caller checks that the user is an administrator.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException when it is no technical ticket
     */
    public function deleteAdminTicket(int $ticketId, string $adminUserId): void {
        $ticket = $this->adminTicket($ticketId);
        $this->ticketMapper->delete($ticket);
        $this->notifier?->ticketRemoved($ticket);
        $this->auditService?->logEvent('support_ticket_deleted', $adminUserId, [
            'ticket_id' => $ticketId,
            'routing_target_type' => 'admin',
            'category' => (string)$ticket->getCategory(),
            'status' => (string)$ticket->getStatus(),
        ]);
    }

    /**
     * Only a ticket of the admin inbox; a question routed to a course belongs to its instructors.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    private function adminTicket(int $ticketId): SupportTicket {
        $ticket = $this->ticketMapper->findById($ticketId);
        if ($ticket->getRoutingTargetType() !== 'admin') {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Ticket not found');
        }
        return $ticket;
    }

    /**
     * GitHub #59: the inbox leaves archived questions out; $archived = true lists only those.
     */
    public function listInstructorForCourse(int $courseId, int $limit = 100, bool $archived = false): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByInstructorCourse($courseId, max(1, min(200, $limit)), $archived));
    }

    /**
     * GitHub #59: unanswered, not archived questions of a course (the badge at its tab).
     */
    public function countOpenForCourse(int $courseId): int {
        return $this->ticketMapper->countOpenByInstructorCourse($courseId);
    }

    /**
     * GitHub #59: an instructor of the course hides a question from the inbox (true) or brings it
     * back (false). A restored question is open or answered again, as before.
     *
     * @throws ForbiddenException when the user is no instructor of the course
     * @throws \OCP\AppFramework\Db\DoesNotExistException when the question is not one of this course
     */
    public function setCourseTicketArchived(int $courseId, int $ticketId, string $userId, bool $archived): array {
        $ticket = $this->managedCourseTicket($courseId, $ticketId, $userId);
        if ($archived) {
            $ticket->setStatus('archived');
            $ticket->setUpdatedAt(time());
            $u = $this->updateAndReread($ticket);
            $this->notifier?->questionSettled($u);
        } else {
            // open or answered is decided by the row as it is now, not by the read above: an
            // answer saved in between makes it answered
            $this->ticketMapper->restoreArchived($ticketId, time());
            $u = $this->ticketMapper->findById($ticketId);
        }
        return $this->serializeTicket($u);
    }

    /**
     * GitHub #59: an instructor of the course deletes a question for good, with its answer and
     * every notification about it.
     *
     * @throws ForbiddenException when the user is no instructor of the course
     * @throws \OCP\AppFramework\Db\DoesNotExistException when the question is not one of this course
     */
    public function deleteCourseTicket(int $courseId, int $ticketId, string $userId): void {
        $ticket = $this->managedCourseTicket($courseId, $ticketId, $userId);
        $this->ticketMapper->delete($ticket);
        $this->notifier?->ticketRemoved($ticket);
    }

    /**
     * GitHub #59: a learner withdraws their own question to the instructors of a course while it
     * has no answer yet. Someone else's question is reported as not found.
     *
     * @throws ForbiddenException when the user cannot see the course
     * @throws \OCP\AppFramework\Db\DoesNotExistException when the question is not the user's in this course
     * @throws \InvalidArgumentException when the question already has an answer
     */
    public function withdrawCourseQuestion(int $courseId, int $ticketId, string $userId): void {
        $this->learnerCourse($courseId, $userId);
        $ticket = $this->ticketMapper->findById($ticketId);
        if ($ticket->getUserId() !== $userId || !$this->belongsToCourse($ticket, $courseId)) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Question not found');
        }
        if (!$this->ticketMapper->deleteOpenOwnQuestion($ticketId, $userId, $courseId)) {
            throw new \InvalidArgumentException('An answered question cannot be withdrawn');
        }
        $this->notifier?->ticketRemoved($ticket);
    }

    /**
     * @throws ForbiddenException when the user is no instructor of the course
     * @throws \OCP\AppFramework\Db\DoesNotExistException when the ticket is not routed to this course
     */
    private function managedCourseTicket(int $courseId, int $ticketId, string $userId): SupportTicket {
        if (!$this->courseService->canManageCourse($courseId, $userId)) {
            throw new ForbiddenException('No permission');
        }
        $ticket = $this->ticketMapper->findById($ticketId);
        // the course in the URL is no authority: the ticket itself must be routed to it
        if (!$this->belongsToCourse($ticket, $courseId)) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Ticket not found');
        }
        return $ticket;
    }

    private function belongsToCourse(SupportTicket $ticket, int $courseId): bool {
        return $ticket->getRoutingTargetType() === 'course_instructor'
            && $ticket->getRoutingCourseId() !== null
            && (int)$ticket->getRoutingCourseId() === $courseId;
    }

    /**
     * GitHub #59: the status as the author sees it. Archiving is the instructors' inbox filter,
     * so an archived question reads as open or answered, whichever it was.
     */
    private function learnerStatus(SupportTicket $ticket): string {
        $status = (string)$ticket->getStatus();
        if ($status !== 'archived') {
            return $status;
        }
        return trim((string)$ticket->getAnswerText()) !== '' ? 'answered' : 'open';
    }

    /**
     * Returns the routing_course_id of a ticket if the given user is instructor
     * of that course, or null otherwise. Used by the answer endpoint.
     */
    public function getManagedCourseIdForTicket(int $ticketId, string $userId): ?int {
        try {
            $ticket = $this->ticketMapper->findById($ticketId);
            if ($ticket->getRoutingTargetType() !== 'course_instructor') {
                return null;
            }
            $courseId = $ticket->getRoutingCourseId();
            if ($courseId === null) {
                return null;
            }
            return $this->courseService->canManageCourse($courseId, $userId) ? $courseId : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function canSeeCourse(int $courseId, string $userId): bool {
        try {
            $this->courseService->findById($courseId, $userId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveRouting(?string $category, ?int $courseId): array {
        // audit #84: any other string (the column holds 32 characters) used to reach the insert
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'technical';
        }
        if ($category === 'course_content' && $courseId !== null) {
            return ['routing_target_type' => 'course_instructor', 'routing_course_id' => $courseId, 'category' => $category];
        }
        return ['routing_target_type' => 'admin', 'routing_course_id' => null, 'category' => $category];
    }

    private function serializeTicket(SupportTicket $ticket): array {
        $data = $ticket->jsonSerialize();
        $data['context'] = !empty($data['context_json']) ? (json_decode((string)$data['context_json'], true) ?: []) : [];
        unset($data['context_json']);
        return $data;
    }

    /**
     * GitHub #59: what a learner sees of their own question. No AI triage fields, no draft and
     * no account id of whoever answered.
     *
     * @return array<string, mixed>
     */
    private function serializeForLearner(SupportTicket $ticket): array {
        return [
            'id' => (int)$ticket->getId(),
            'subject' => (string)$ticket->getSubject(),
            'message' => (string)$ticket->getMessage(),
            'status' => $this->learnerStatus($ticket),
            'answer_text' => $ticket->getAnswerText(),
            'answered_at' => $ticket->getAnsweredAt() !== null ? (int)$ticket->getAnsweredAt() : null,
            'created_at' => (int)$ticket->getCreatedAt(),
        ];
    }

    private function buildFallbackSubject(array $context): string {
        $parts = [];
        $area = trim((string)($context['area'] ?? ''));
        if ($area !== '') {
            $parts[] = $area;
        }
        $courseTitle = trim((string)($context['courseTitle'] ?? ''));
        if ($courseTitle !== '') {
            $parts[] = $courseTitle;
        }
        $poolName = trim((string)($context['poolName'] ?? ''));
        if ($poolName !== '') {
            $parts[] = $poolName;
        }
        return $parts !== [] ? implode(' | ', array_slice($parts, 0, 3)) : 'VirtuProf support request';
    }

    private function normalizeInt($value): ?int {
        return is_numeric($value) ? (int)$value : null;
    }

    private function normalizeString($value, int $maxLength): ?string {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }
        return mb_substr($normalized, 0, $maxLength);
    }
}
