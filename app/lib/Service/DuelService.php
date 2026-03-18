<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\DuelAnswer;
use OCA\Learning\Db\DuelAnswerMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class DuelService {
    private DuelSessionMapper $sessionMapper;
    private DuelAnswerMapper $answerMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    /** Tie-break threshold in milliseconds */
    private const TIE_THRESHOLD_MS = 50;

    /** Inactivity timeout in seconds */
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        DuelSessionMapper $sessionMapper,
        DuelAnswerMapper $answerMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->sessionMapper = $sessionMapper;
        $this->answerMapper = $answerMapper;
        $this->db = $db;
        $this->logger = $logger;
    }

    // ---------- Public API ----------

    public function createDuel(int $poolId, string $userId): array {
        $questionIds = $this->selectQuestions($poolId);
        $code = $this->generateCode();

        $session = new DuelSession();
        $session->setCode($code);
        $session->setCreatorUid($userId);
        $session->setOpponentUid(null);
        $session->setPoolId($poolId);
        $session->setQuestionIds(json_encode($questionIds));
        $session->setStatus('waiting');
        $session->setCurrentQuestionIndex(0);
        $session->setCreatorScore(0);
        $session->setOpponentScore(0);
        $session->setCreatorReady(false);
        $session->setOpponentReady(false);
        $session->setCreatorLastPoll(null);
        $session->setOpponentLastPoll(null);
        $session->setCreatedAt(time());

        $session = $this->sessionMapper->insert($session);
        return $this->buildState($session, $userId);
    }

    public function joinDuel(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'waiting') {
            throw new \RuntimeException('Duel is no longer open for joining');
        }
        if ($session->getCreatorUid() === $userId) {
            throw new \RuntimeException('You created this duel, you cannot join it as opponent');
        }
        if ($session->getOpponentUid() !== null) {
            throw new \RuntimeException('Duel already has an opponent');
        }

        $session->setOpponentUid($userId);
        $session->setStatus('ready');
        $session = $this->sessionMapper->update($session);

        return $this->buildState($session, $userId);
    }

    public function setReady(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        if ($session->getCreatorUid() === $userId) {
            $session->setCreatorReady(true);
        } else {
            $session->setOpponentReady(true);
        }

        // Both ready → activate
        if ($session->getCreatorReady() && $session->getOpponentReady()) {
            $session->setStatus('active');
        }

        $session = $this->sessionMapper->update($session);
        return $this->buildState($session, $userId);
    }

    public function getState(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        // Update last poll timestamp
        $now = time();
        if ($session->getCreatorUid() === $userId) {
            $session->setCreatorLastPoll($now);
        } else {
            $session->setOpponentLastPoll($now);
        }

        // Check for abandoned opponent
        if (in_array($session->getStatus(), ['ready', 'active'], true)) {
            $cutoff = $now - self::TIMEOUT_SECONDS;
            $otherLastPoll = $session->getCreatorUid() === $userId
                ? $session->getOpponentLastPoll()
                : $session->getCreatorLastPoll();

            if ($otherLastPoll !== null && $otherLastPoll < $cutoff) {
                // Other player timed out — current player wins by forfeit
                $session->setStatus('expired');
                if ($session->getCreatorUid() === $userId) {
                    // Creator wins — give creator a big score advantage
                    $session->setCreatorScore($session->getCreatorScore() + 100);
                } else {
                    $session->setOpponentScore($session->getOpponentScore() + 100);
                }
            }
        }

        $session = $this->sessionMapper->update($session);
        return $this->buildState($session, $userId);
    }

    public function submitAnswer(string $code, string $userId, bool $answerCorrect, int $answeredAt): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        if ($session->getStatus() !== 'active') {
            throw new \RuntimeException('Duel is not active');
        }

        $questionIndex = $session->getCurrentQuestionIndex();

        // Check player hasn't already answered this question
        $existingAnswers = $this->answerMapper->findByDuelAndQuestion($session->getId(), $questionIndex);
        foreach ($existingAnswers as $existing) {
            if ($existing->getPlayerUid() === $userId) {
                throw new \RuntimeException('You already answered this question');
            }
        }

        // Insert answer
        $answer = new DuelAnswer();
        $answer->setDuelId($session->getId());
        $answer->setQuestionIndex($questionIndex);
        $answer->setPlayerUid($userId);
        $answer->setAnswerCorrect($answerCorrect);
        $answer->setAnsweredAt($answeredAt);
        $answer->setPointsEarned(0);
        $this->answerMapper->insert($answer);

        // Check if both have answered
        $answers = $this->answerMapper->findByDuelAndQuestion($session->getId(), $questionIndex);
        if (count($answers) === 2) {
            $session = $this->applyScoring($session, $answers);
        }

        return $this->buildState($session, $userId);
    }

    public function rematch(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        if (!in_array($session->getStatus(), ['finished', 'expired'], true)) {
            throw new \RuntimeException('Duel must be finished or expired to request a rematch');
        }

        $newQuestionIds = $this->selectQuestions($session->getPoolId());
        $newCode = $this->generateCode();

        $newSession = new DuelSession();
        $newSession->setCode($newCode);
        $newSession->setCreatorUid($session->getCreatorUid());
        $newSession->setOpponentUid($session->getOpponentUid());
        $newSession->setPoolId($session->getPoolId());
        $newSession->setQuestionIds(json_encode($newQuestionIds));
        $newSession->setStatus('ready');
        $newSession->setCurrentQuestionIndex(0);
        $newSession->setCreatorScore(0);
        $newSession->setOpponentScore(0);
        $newSession->setCreatorReady(false);
        $newSession->setOpponentReady(false);
        $newSession->setCreatorLastPoll(null);
        $newSession->setOpponentLastPoll(null);
        $newSession->setCreatedAt(time());

        $newSession = $this->sessionMapper->insert($newSession);
        return ['new_code' => $newCode] + $this->buildState($newSession, $userId);
    }

    // ---------- Private helpers ----------

    private function assertParticipant(DuelSession $session, string $userId): void {
        if ($session->getCreatorUid() !== $userId && $session->getOpponentUid() !== $userId) {
            throw new \RuntimeException('You are not a participant in this duel');
        }
    }

    private function generateCode(): string {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = bin2hex(random_bytes(3));
            try {
                $this->sessionMapper->findByCode($code);
                // Code already exists, retry
            } catch (DoesNotExistException $e) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate unique duel code after 10 attempts');
    }

    private function selectQuestions(int $poolId): array {
        $qb = $this->db->getQueryBuilder();

        // Try to get true/false questions first
        $qb->select('id')
           ->from('learning_questions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_type', $qb->createNamedParameter('true_false')));
        $result = $qb->executeQuery();
        $trueFalseIds = [];
        while ($row = $result->fetch()) {
            $trueFalseIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        // If we have fewer than 10, get all questions from the pool
        if (count($trueFalseIds) < 10) {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('id')
                ->from('learning_questions')
                ->where($qb2->expr()->eq('pool_id', $qb2->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
            $result2 = $qb2->executeQuery();
            $allIds = [];
            while ($row = $result2->fetch()) {
                $allIds[] = (int)$row['id'];
            }
            $result2->closeCursor();

            shuffle($allIds);
            return array_slice($allIds, 0, 10);
        }

        shuffle($trueFalseIds);
        return array_slice($trueFalseIds, 0, 10);
    }

    private function applyScoring(DuelSession $session, array $answers): DuelSession {
        // Sort answers: identify creator and opponent
        $creatorAnswer = null;
        $opponentAnswer = null;
        foreach ($answers as $answer) {
            if ($answer->getPlayerUid() === $session->getCreatorUid()) {
                $creatorAnswer = $answer;
            } else {
                $opponentAnswer = $answer;
            }
        }

        if ($creatorAnswer === null || $opponentAnswer === null) {
            return $session;
        }

        $creatorCorrect = $creatorAnswer->getAnswerCorrect();
        $opponentCorrect = $opponentAnswer->getAnswerCorrect();
        $creatorAt = $creatorAnswer->getAnsweredAt();
        $opponentAt = $opponentAnswer->getAnsweredAt();

        $creatorPoints = 0;
        $opponentPoints = 0;

        if ($creatorCorrect && $opponentCorrect) {
            $diff = abs($creatorAt - $opponentAt);
            if ($diff <= self::TIE_THRESHOLD_MS) {
                // Both correct, tied
                $creatorPoints = 3;
                $opponentPoints = 3;
            } elseif ($creatorAt < $opponentAt) {
                // Creator was faster
                $creatorPoints = 3;
                $opponentPoints = 2;
            } else {
                // Opponent was faster
                $creatorPoints = 2;
                $opponentPoints = 3;
            }
        } elseif ($creatorCorrect && !$opponentCorrect) {
            // Creator correct + steal bonus, opponent gets nothing
            $creatorPoints = 4;
            $opponentPoints = 0;
        } elseif (!$creatorCorrect && $opponentCorrect) {
            // Opponent correct + steal bonus, creator gets nothing
            $creatorPoints = 0;
            $opponentPoints = 4;
        } else {
            // Both wrong
            $creatorPoints = -1;
            $opponentPoints = -1;
        }

        // Update answer records
        $creatorAnswer->setPointsEarned($creatorPoints);
        $this->answerMapper->update($creatorAnswer);
        $opponentAnswer->setPointsEarned($opponentPoints);
        $this->answerMapper->update($opponentAnswer);

        // Update session scores
        $session->setCreatorScore($session->getCreatorScore() + $creatorPoints);
        $session->setOpponentScore($session->getOpponentScore() + $opponentPoints);

        // Advance question index
        $nextIndex = $session->getCurrentQuestionIndex() + 1;
        if ($nextIndex >= 10) {
            $session->setStatus('finished');
        } else {
            $session->setCurrentQuestionIndex($nextIndex);
        }

        return $this->sessionMapper->update($session);
    }

    private function buildState(DuelSession $session, string $userId): array {
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];
        $currentIndex = $session->getCurrentQuestionIndex();
        $status = $session->getStatus();

        $currentQuestion = null;
        if ($status === 'active' && $currentIndex < count($questionIds)) {
            $questionId = $questionIds[$currentIndex];
            $currentQuestion = $this->loadQuestion((int)$questionId);
        }

        $myRole = $session->getCreatorUid() === $userId ? 'creator' : 'opponent';

        return [
            'code' => $session->getCode(),
            'status' => $status,
            'creator_uid' => $session->getCreatorUid(),
            'opponent_uid' => $session->getOpponentUid(),
            'creator_score' => $session->getCreatorScore(),
            'opponent_score' => $session->getOpponentScore(),
            'creator_ready' => (bool)$session->getCreatorReady(),
            'opponent_ready' => (bool)$session->getOpponentReady(),
            'current_question_index' => $currentIndex,
            'total_questions' => 10,
            'current_question' => $currentQuestion,
            'my_role' => $myRole,
        ];
    }

    private function loadQuestion(int $questionId): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text', 'image_path')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return null;
            }

            return [
                'id' => (int)$row['id'],
                'text' => $row['text'],
                'image_path' => $row['image_path'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('DuelService: Failed to load question ' . $questionId . ': ' . $e->getMessage());
            return null;
        }
    }
}
