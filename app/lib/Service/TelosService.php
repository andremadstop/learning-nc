<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * TelosService — Phase 75: Personal learning profile management.
 *
 * CRUD for user_telos + interview orchestration.
 */
class TelosService {
    private UserTelosMapper $mapper;
    private GeminiService $geminiService;
    private LoggerInterface $logger;
    private IDBConnection $db;
    private IUserManager $userManager;

    /** Valid visibility values */
    private const VALID_VISIBILITY = ['private', 'course', 'public'];

    /** Interview questions for VirtuProf onboarding */
    private const INTERVIEW_QUESTIONS = [
        'role' => 'Was machst du beruflich oder in der Ausbildung?',
        'experience' => 'Wie lange arbeitest du schon mit Computern/Netzwerken?',
        'strengths' => 'Welche IT-Themen kennst du schon gut?',
        'weaknesses' => 'Wo fuehlst du dich unsicher?',
        'target_cert' => 'Welche Zertifizierung oder Pruefung willst du machen?',
        'target_date' => 'Wann ist die Pruefung / bis wann willst du fertig sein?',
        'learning_style' => 'Lernst du lieber allein oder in der Gruppe?',
        'hours_per_week' => 'Wie viel Zeit pro Woche kannst du investieren?',
        'motivation' => 'Was treibt dich an? (Jobwechsel, Befoerderung, Interesse)',
        'notes' => 'Gibt es etwas das ich noch wissen sollte?',
    ];

    public function __construct(
        UserTelosMapper $mapper,
        GeminiService $geminiService,
        LoggerInterface $logger,
        IDBConnection $db,
        IUserManager $userManager
    ) {
        $this->mapper = $mapper;
        $this->geminiService = $geminiService;
        $this->logger = $logger;
        $this->db = $db;
        $this->userManager = $userManager;
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * Get telos for a user (or null if not yet created).
     */
    public function getTelos(string $userId): ?array {
        $entity = $this->mapper->findByUserIdOrNull($userId);
        if ($entity === null) {
            return null;
        }
        return $this->entityToArray($entity);
    }

    /**
     * Check if user has completed onboarding.
     */
    public function hasCompletedOnboarding(string $userId): bool {
        $entity = $this->mapper->findByUserIdOrNull($userId);
        return $entity !== null && $entity->getOnboardingCompleted();
    }

    /**
     * Save or update telos data.
     *
     * @param array<string, mixed> $telosData  The structured telos fields
     * @param array<string, mixed> $extra       Optional: bio, help_offer, help_wanted, visibility
     */
    public function saveTelos(string $userId, array $telosData, array $extra = []): array {
        $now = time();
        $entity = $this->mapper->findByUserIdOrNull($userId);

        if ($entity === null) {
            $entity = new UserTelos();
            $entity->setUserId($userId);
            $entity->setCreatedAt($now);
        }

        $entity->setTelosData($telosData);
        $entity->setOnboardingCompleted(true);
        $entity->setUpdatedAt($now);

        // Optional extra fields
        if (isset($extra['bio'])) {
            $entity->setBio(mb_substr(strip_tags((string)$extra['bio']), 0, 1000));
        }
        if (isset($extra['help_offer']) && is_array($extra['help_offer'])) {
            $entity->setHelpOffer(json_encode(array_slice($extra['help_offer'], 0, 10), JSON_UNESCAPED_UNICODE));
        }
        if (isset($extra['help_wanted']) && is_array($extra['help_wanted'])) {
            $entity->setHelpWanted(json_encode(array_slice($extra['help_wanted'], 0, 10), JSON_UNESCAPED_UNICODE));
        }
        if (isset($extra['visibility']) && in_array($extra['visibility'], self::VALID_VISIBILITY, true)) {
            $entity->setVisibility($extra['visibility']);
        }

        if ($entity->getId() === null) {
            $entity = $this->mapper->insert($entity);
        } else {
            $entity = $this->mapper->update($entity);
        }

        return $this->entityToArray($entity);
    }

    /**
     * Update individual fields without overwriting the full telos.
     */
    public function updateFields(string $userId, array $fields): ?array {
        $entity = $this->mapper->findByUserIdOrNull($userId);
        if ($entity === null) {
            return null;
        }

        $now = time();

        if (isset($fields['telos']) && is_array($fields['telos'])) {
            $current = $entity->getTelosData();
            $entity->setTelosData(array_merge($current, $fields['telos']));
        }
        if (isset($fields['bio'])) {
            $entity->setBio(mb_substr(strip_tags((string)$fields['bio']), 0, 1000));
        }
        if (isset($fields['help_offer']) && is_array($fields['help_offer'])) {
            $entity->setHelpOffer(json_encode(array_slice($fields['help_offer'], 0, 10), JSON_UNESCAPED_UNICODE));
        }
        if (isset($fields['help_wanted']) && is_array($fields['help_wanted'])) {
            $entity->setHelpWanted(json_encode(array_slice($fields['help_wanted'], 0, 10), JSON_UNESCAPED_UNICODE));
        }
        if (isset($fields['visibility']) && in_array($fields['visibility'], self::VALID_VISIBILITY, true)) {
            $entity->setVisibility($fields['visibility']);
        }

        $entity->setUpdatedAt($now);
        $entity = $this->mapper->update($entity);

        return $this->entityToArray($entity);
    }

    // =========================================================================
    // Interview
    // =========================================================================

    /**
     * Get the interview questions for the onboarding flow.
     *
     * @return array<string, string>
     */
    public function getInterviewQuestions(): array {
        return self::INTERVIEW_QUESTIONS;
    }

    /**
     * Process interview answers via Gemini to extract structured telos.
     *
     * @param string $userId
     * @param string $conversationText  The full interview conversation (user answers)
     * @return array{telos: array<string, mixed>, saved: bool, error?: string}
     */
    public function processInterview(string $userId, string $conversationText): array {
        try {
            $chatResult = $this->geminiService->generateStructured(
                $this->buildExtractionPrompt(),
                $conversationText,
                $userId,
                512,
                'ai_telos_extract'
            );

            $result = $chatResult['answer'] ?? '';

            if ($result === '') {
                return ['telos' => [], 'saved' => false, 'error' => $chatResult['error'] ?? 'Empty Gemini response'];
            }

            // Parse JSON from Gemini response
            $telos = $this->parseTelosFromResponse($result);

            if (empty($telos)) {
                return ['telos' => [], 'saved' => false, 'error' => 'Could not parse telos from response'];
            }

            // Save to DB
            $this->saveTelos($userId, $telos);

            return ['telos' => $telos, 'saved' => true];
        } catch (\Throwable $e) {
            $this->logger->warning('Telos interview processing failed: ' . $e->getMessage());
            return ['telos' => [], 'saved' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Buddy Matching (Phase 100)
    // =========================================================================

    /**
     * Find study buddies for a user within a course based on help_offer/help_wanted overlap.
     *
     * @return array{can_help_me: list<array{user_id: string, display_name: string, topics: string[]}>, i_can_help: list<array{user_id: string, display_name: string, topics: string[]}>}
     */
    public function getCourseBuddies(string $userId, int $courseId): array {
        // 1. Get all enrolled user IDs for this course (excluding current user)
        $qb = $this->db->getQueryBuilder();
        $qb->select('cm.user_id')
           ->from('learning_course_members', 'cm')
           ->where($qb->expr()->eq('cm.course_id', $qb->createNamedParameter($courseId)))
           ->andWhere($qb->expr()->neq('cm.user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $memberIds = [];
        while ($row = $result->fetch()) {
            $memberIds[] = $row['user_id'];
        }
        $result->closeCursor();

        if (empty($memberIds)) {
            return ['can_help_me' => [], 'i_can_help' => []];
        }

        // 2. Get current user's telos
        $myTelos = $this->mapper->findByUserIdOrNull($userId);
        if ($myTelos === null) {
            return ['can_help_me' => [], 'i_can_help' => []];
        }
        $myHelpWanted = $myTelos->getHelpWantedList();
        $myHelpOffer = $myTelos->getHelpOfferList();

        // 3. Get telos for all other enrolled members (non-private visibility only)
        $otherTelos = $this->mapper->findByUserIds($memberIds);

        $canHelpMe = [];
        $iCanHelp = [];

        foreach ($otherTelos as $entity) {
            if ($entity->getVisibility() === 'private') {
                continue;
            }

            $otherId = $entity->getUserId();
            $otherOffer = $entity->getHelpOfferList();
            $otherWanted = $entity->getHelpWantedList();

            // Others who can help me: their offer intersects my wanted
            $matchedForMe = array_values(array_intersect($otherOffer, $myHelpWanted));
            if (!empty($matchedForMe)) {
                $canHelpMe[] = [
                    'user_id' => $otherId,
                    'display_name' => $this->getDisplayName($otherId),
                    'topics' => $matchedForMe,
                ];
            }

            // Others I can help: my offer intersects their wanted
            $matchedForThem = array_values(array_intersect($myHelpOffer, $otherWanted));
            if (!empty($matchedForThem)) {
                $iCanHelp[] = [
                    'user_id' => $otherId,
                    'display_name' => $this->getDisplayName($otherId),
                    'topics' => $matchedForThem,
                ];
            }
        }

        // Sort by number of matched topics (most matches first), limit to 20
        usort($canHelpMe, fn($a, $b) => count($b['topics']) <=> count($a['topics']));
        usort($iCanHelp, fn($a, $b) => count($b['topics']) <=> count($a['topics']));

        return [
            'can_help_me' => array_slice($canHelpMe, 0, 20),
            'i_can_help' => array_slice($iCanHelp, 0, 20),
        ];
    }

    /**
     * Get display name for a user, falling back to user ID.
     */
    private function getDisplayName(string $userId): string {
        $user = $this->userManager->get($userId);
        return $user !== null ? $user->getDisplayName() : $userId;
    }

    // =========================================================================
    // Aggregation (for dozent view)
    // =========================================================================

    /**
     * Aggregate telos data for a list of users (course members).
     *
     * @param string[] $userIds
     * @return array{
     *   total: int,
     *   experience_levels: array<string, int>,
     *   target_certs: array<string, int>,
     *   avg_hours_per_week: float,
     *   upcoming_exams: list<array{user_id: string, target_cert: string, target_date: string, days_until: int}>,
     *   onboarded: int
     * }
     */
    public function aggregateForCourse(array $userIds): array {
        $entities = $this->mapper->findByUserIds($userIds);

        $levels = [];
        $certs = [];
        $totalHours = 0;
        $hoursCount = 0;
        $exams = [];
        $onboarded = 0;

        foreach ($entities as $entity) {
            if ($entity->getOnboardingCompleted()) {
                $onboarded++;
            }

            $data = $entity->getTelosData();
            if (empty($data)) {
                continue;
            }

            // Experience levels
            $level = $data['experience_level'] ?? 'unknown';
            $levels[$level] = ($levels[$level] ?? 0) + 1;

            // Target certs
            $cert = $data['target_cert'] ?? '';
            if ($cert !== '') {
                $certs[$cert] = ($certs[$cert] ?? 0) + 1;
            }

            // Hours per week
            $hours = (float)($data['hours_per_week'] ?? 0);
            if ($hours > 0) {
                $totalHours += $hours;
                $hoursCount++;
            }

            // Upcoming exams
            $targetDate = $data['target_date'] ?? '';
            if ($targetDate !== '' && strtotime($targetDate) !== false) {
                $daysUntil = (int)round((strtotime($targetDate) - time()) / 86400);
                if ($daysUntil >= 0 && $daysUntil <= 180) {
                    $exams[] = [
                        'user_id' => $entity->getUserId(),
                        'target_cert' => $cert,
                        'target_date' => $targetDate,
                        'days_until' => $daysUntil,
                    ];
                }
            }
        }

        // Sort exams by date (soonest first)
        usort($exams, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

        return [
            'total' => count($userIds),
            'experience_levels' => $levels,
            'target_certs' => $certs,
            'avg_hours_per_week' => $hoursCount > 0 ? round($totalHours / $hoursCount, 1) : 0,
            'upcoming_exams' => array_slice($exams, 0, 20),
            'onboarded' => $onboarded,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function entityToArray(UserTelos $entity): array {
        return [
            'user_id' => $entity->getUserId(),
            'telos' => $entity->getTelosData(),
            'bio' => $entity->getBio() ?? '',
            'help_offer' => $entity->getHelpOfferList(),
            'help_wanted' => $entity->getHelpWantedList(),
            'visibility' => $entity->getVisibility(),
            'onboarding_completed' => $entity->getOnboardingCompleted(),
            'created_at' => $entity->getCreatedAt(),
            'updated_at' => $entity->getUpdatedAt(),
        ];
    }

    private function buildExtractionPrompt(): string {
        return <<<PROMPT
Extract a structured learning profile from the following interview conversation.
Return ONLY a JSON object with these exact keys (no markdown, no explanation):

{
  "role": "string — job/education role",
  "experience_level": "beginner|intermediate|advanced",
  "strengths": ["string array — IT topics they know well"],
  "weaknesses": ["string array — IT topics they struggle with"],
  "target_cert": "string — target certification (e.g. CompTIA Network+ N10-009)",
  "target_date": "YYYY-MM-DD or empty string",
  "hours_per_week": number,
  "learning_style": "solo|group|mixed",
  "motivation": "string — what drives them",
  "notes": "string — anything else relevant"
}
PROMPT;
    }

    /**
     * Parse telos JSON from Gemini response (may contain markdown wrapper).
     *
     * @return array<string, mixed>
     */
    private function parseTelosFromResponse(string $response): array {
        // Try direct JSON parse
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['role'])) {
            return $data;
        }

        // Try extracting JSON from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $response, $matches)) {
            $data = json_decode($matches[1], true);
            if (is_array($data) && isset($data['role'])) {
                return $data;
            }
        }

        // Try finding first { ... } block
        if (preg_match('/\{[^{}]*"role"[^{}]*\}/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }
}
