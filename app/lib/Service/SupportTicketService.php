<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCP\IConfig;

class SupportTicketService {
    private SupportTicketMapper $ticketMapper;
    private CourseService $courseService;
    private GeminiService $geminiService;
    private IConfig $config;

    public function __construct(
        SupportTicketMapper $ticketMapper,
        CourseService $courseService,
        GeminiService $geminiService,
        IConfig $config
    ) {
        $this->ticketMapper = $ticketMapper;
        $this->courseService = $courseService;
        $this->geminiService = $geminiService;
        $this->config = $config;
    }

    public function create(string $userId, ?string $subject, string $message, array $context = [], ?string $category = null): SupportTicket {
        $subject = trim((string)$subject);
        $message = trim($message);
        if ($subject === '') {
            $subject = $this->buildFallbackSubject($context);
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

        $ticket = $this->ticketMapper->insert($ticket);

        // AI Triage: classify ticket if AI is enabled
        if ($this->config->getAppValue('learning', 'ai_enabled', 'no') === 'yes') {
            $this->applyAiTriage($ticket, $subject, $message);
        }

        return $ticket;
    }

    /**
     * Run AI triage classification and persist results.
     * Called after initial ticket insert — updates the ticket record with AI fields.
     */
    private function applyAiTriage(SupportTicket $ticket, string $subject, string $message): void {
        try {
            $result = $this->geminiService->classifyTicket($subject, $message);

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
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByUser($userId, max(1, min(100, $limit))));
    }

    public function listRecent(int $limit = 100): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findRecent(max(1, min(200, $limit))));
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

        $ticket->setStatus('answered');
        $ticket->setAnswerText($answerText);
        $ticket->setAnsweredBy($answeredBy);
        $ticket->setAnsweredAt(time());
        $ticket->setUpdatedAt(time());

        return $this->serializeTicket($this->ticketMapper->update($ticket));
    }

    public function listAdmin(int $limit = 100): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByRoutingTarget('admin', max(1, min(200, $limit))));
    }

    public function listInstructorForCourse(int $courseId, int $limit = 100): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByInstructorCourse($courseId, max(1, min(200, $limit))));
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

    private function resolveRouting(?string $category, ?int $courseId): array {
        if ($category === 'course_content' && $courseId !== null) {
            return ['routing_target_type' => 'course_instructor', 'routing_course_id' => $courseId, 'category' => $category];
        }
        return ['routing_target_type' => 'admin', 'routing_course_id' => null, 'category' => $category ?? 'technical'];
    }

    private function serializeTicket(SupportTicket $ticket): array {
        $data = $ticket->jsonSerialize();
        $data['context'] = !empty($data['context_json']) ? (json_decode((string)$data['context_json'], true) ?: []) : [];
        unset($data['context_json']);
        return $data;
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
