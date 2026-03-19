<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;

class SupportTicketService {
    private SupportTicketMapper $ticketMapper;

    public function __construct(SupportTicketMapper $ticketMapper) {
        $this->ticketMapper = $ticketMapper;
    }

    public function create(string $userId, ?string $subject, string $message, array $context = []): SupportTicket {
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

        return $this->ticketMapper->insert($ticket);
    }

    public function listMine(string $userId, int $limit = 50): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findByUser($userId, max(1, min(100, $limit))));
    }

    public function listRecent(int $limit = 100): array {
        return array_map([$this, 'serializeTicket'], $this->ticketMapper->findRecent(max(1, min(200, $limit))));
    }

    public function answer(int $ticketId, string $answerText, string $answeredBy): array {
        $answerText = trim($answerText);
        if ($answerText === '') {
            throw new \InvalidArgumentException('Answer text is required');
        }
        if (mb_strlen($answerText) > 5000) {
            throw new \InvalidArgumentException('Answer text too long');
        }

        $ticket = $this->ticketMapper->findById($ticketId);
        $ticket->setStatus('answered');
        $ticket->setAnswerText($answerText);
        $ticket->setAnsweredBy($answeredBy);
        $ticket->setAnsweredAt(time());
        $ticket->setUpdatedAt(time());

        return $this->serializeTicket($this->ticketMapper->update($ticket));
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
