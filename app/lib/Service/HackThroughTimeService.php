<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\EpochProgress;
use OCA\Learning\Db\EpochProgressMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * HackThroughTimeService — backend for the Zeitreise game mode (Phase 48).
 *
 * Manages epoch progress, museum facts, skill-check logic with
 * character-class affinity modifiers. Parallel PHP definitions of
 * epoch and museum data (JS versions exist for frontend consumption).
 */
class HackThroughTimeService {

    /** Valid character classes for Hack Through Time. */
    private const VALID_CLASSES = ['phreaker', 'scriptkiddie', 'redteamer', 'quantum_defender'];

    /** Number of skill-check questions per epoch. */
    private const SKILL_CHECK_COUNT = 5;

    /**
     * Epoch definitions — parallel to app/data/epochs/epochs.js.
     * Keys match JS epoch IDs exactly.
     */
    private const EPOCHS = [
        'phone_phreaking' => [
            'id' => 'phone_phreaking', 'name' => 'Phone Phreaking', 'era' => '1960er',
            'yearRange' => '1960-1979', 'themeKey' => 'terminal-green', 'order' => 1,
            'description' => 'Die Geburt des Hackings: Telefon-Phreaker manipulieren analoge Vermittlungsstellen mit Tonfrequenzen.',
            'poolFilter' => 'epoch:phone_phreaking',
        ],
        'wargames' => [
            'id' => 'wargames', 'name' => 'WarGames', 'era' => '1980er',
            'yearRange' => '1980-1989', 'themeKey' => 'dos-blue', 'order' => 2,
            'description' => 'BBS-Kultur, Social Engineering und die ersten Computer-Einbrueche.',
            'poolFilter' => 'epoch:wargames',
        ],
        'morris_worm' => [
            'id' => 'morris_worm', 'name' => 'The Worm', 'era' => '1990er',
            'yearRange' => '1988-1999', 'themeKey' => 'netscape-grey', 'order' => 3,
            'description' => 'Der Morris-Worm legt das Internet lahm, CERT wird gegruendet.',
            'poolFilter' => 'epoch:morris_worm',
        ],
        'sql_injection' => [
            'id' => 'sql_injection', 'name' => 'Bobby Tables', 'era' => '2000er',
            'yearRange' => '2000-2009', 'themeKey' => 'xp-luna', 'order' => 4,
            'description' => 'SQL Injection, Code Red, MySpace-Worm Samy — Web-Security wird geboren.',
            'poolFilter' => 'epoch:sql_injection',
        ],
        'apt_nation_states' => [
            'id' => 'apt_nation_states', 'name' => 'The Shadow Brokers', 'era' => '2010er',
            'yearRange' => '2010-2019', 'themeKey' => 'dark-terminal', 'order' => 5,
            'description' => 'Stuxnet, Snowden, EternalBlue — Staaten fuehren Cyberkrieg.',
            'poolFilter' => 'epoch:apt_nation_states',
        ],
        'supply_chain' => [
            'id' => 'supply_chain', 'name' => 'Supply Chain', 'era' => '2020er',
            'yearRange' => '2020-2029', 'themeKey' => 'cloud-dashboard', 'order' => 6,
            'description' => 'SolarWinds, Log4Shell, Prompt Injection — die Lieferkette wird zum Angriffsvektor.',
            'poolFilter' => 'epoch:supply_chain',
        ],
        'quantum_threat' => [
            'id' => 'quantum_threat', 'name' => 'Quantum Dawn', 'era' => 'Zukunft',
            'yearRange' => '2030+', 'themeKey' => 'hologram-glow', 'order' => 7,
            'description' => 'Post-Quantum-Kryptografie, Quantenschluessel und Shors Algorithmus.',
            'poolFilter' => 'epoch:quantum_threat',
        ],
    ];

    /**
     * Character class affinity mappings — parallel to characterClasses.js.
     * Keys: classId => ['affinity' => [...epochIds], 'weak' => [...epochIds]]
     */
    private const CLASS_AFFINITIES = [
        'phreaker'         => ['affinity' => ['phone_phreaking', 'wargames'], 'weak' => ['quantum_threat']],
        'scriptkiddie'     => ['affinity' => ['morris_worm', 'sql_injection'], 'weak' => ['phone_phreaking']],
        'redteamer'        => ['affinity' => ['apt_nation_states', 'supply_chain'], 'weak' => ['phone_phreaking']],
        'quantum_defender' => ['affinity' => ['quantum_threat'], 'weak' => ['wargames', 'morris_worm']],
    ];

    /**
     * Museum facts — parallel to app/data/epochs/museum.js.
     * Keyed by epoch ID, each epoch has array of fact objects.
     */
    private const MUSEUM_FACTS = [
        'phone_phreaking' => [
            ['id' => 'pp_1', 'year' => 1957, 'title' => 'Joe Engressia entdeckt 2600 Hz', 'people' => ['Joe Engressia'], 'impact' => 'Ein blinder Teenager entdeckt, dass ein 2600-Hz-Pfeifton die AT&T-Vermittlung steuert.'],
            ['id' => 'pp_2', 'year' => 1971, 'title' => 'Captain Crunch und die Blue Box', 'people' => ['John Draper'], 'impact' => 'Eine Spielzeugpfeife erzeugt exakt 2600 Hz — kostenlose Ferngespraeche weltweit.'],
            ['id' => 'pp_3', 'year' => 1975, 'title' => 'ARPANET waechst', 'people' => ['Vint Cerf', 'Bob Kahn'], 'impact' => 'TCP/IP verbindet erstmals verschiedene Netzwerke — das Internet entsteht.'],
            ['id' => 'pp_4', 'year' => 1978, 'title' => 'Das 2600 Magazine', 'people' => ['Emmanuel Goldstein'], 'impact' => 'Die erste Hacker-Zeitschrift wird zum Sprachrohr der Community.'],
        ],
        'wargames' => [
            ['id' => 'wg_1', 'year' => 1983, 'title' => 'WarGames im Kino', 'people' => ['Matthew Broderick'], 'impact' => 'Der Film sensibilisiert die Oeffentlichkeit fuer Computer-Sicherheit.'],
            ['id' => 'wg_2', 'year' => 1984, 'title' => 'Chaos Computer Club gegruendet', 'people' => ['Wau Holland', 'Steffen Wernery'], 'impact' => 'Europas groesster Hacker-Verein entsteht in Hamburg.'],
            ['id' => 'wg_3', 'year' => 1986, 'title' => 'Das KGB-Hack-Netzwerk', 'people' => ['Markus Hess', 'Cliff Stoll'], 'impact' => 'Deutsche Hacker brechen in US-Militaerrechner ein und verkaufen Daten an den KGB.'],
            ['id' => 'wg_4', 'year' => 1988, 'title' => 'Kevin Mitnick auf der Flucht', 'people' => ['Kevin Mitnick'], 'impact' => 'Der beruechtigtste Social-Engineer Amerikas wird zum meistgesuchten Hacker des FBI.'],
        ],
        'morris_worm' => [
            ['id' => 'mw_1', 'year' => 1988, 'title' => 'Der Morris-Worm', 'people' => ['Robert Tappan Morris'], 'impact' => 'Der erste Internet-Wurm infiziert 6.000 Rechner und legt sie lahm.'],
            ['id' => 'mw_2', 'year' => 1988, 'title' => 'CERT wird gegruendet', 'people' => ['DARPA'], 'impact' => 'Als Reaktion auf den Morris-Worm gruendet DARPA das erste Computer Emergency Response Team.'],
            ['id' => 'mw_3', 'year' => 1990, 'title' => 'Erste Verurteilung nach CFAA', 'people' => ['Robert Tappan Morris'], 'impact' => 'Morris wird als erster Mensch unter dem Computer Fraud and Abuse Act verurteilt.'],
        ],
        'sql_injection' => [
            ['id' => 'si_1', 'year' => 2001, 'title' => 'OWASP gegruendet', 'people' => ['Mark Curphey'], 'impact' => 'Das Open Web Application Security Project entsteht.'],
            ['id' => 'si_2', 'year' => 2001, 'title' => 'Code Red Wurm', 'people' => [], 'impact' => 'Code Red infiziert 359.000 IIS-Server in 14 Stunden.'],
            ['id' => 'si_3', 'year' => 2005, 'title' => 'Samy — der MySpace-Wurm', 'people' => ['Samy Kamkar'], 'impact' => 'Eine Million Freundschaftsanfragen in 20 Stunden via XSS.'],
            ['id' => 'si_4', 'year' => 2007, 'title' => 'SQL Injection wird #1', 'people' => [], 'impact' => 'SQL Injection fuehrt erstmals die OWASP Top 10 an.'],
            ['id' => 'si_5', 'year' => 2009, 'title' => 'Heartland Payment Systems', 'people' => ['Albert Gonzalez'], 'impact' => '130 Millionen Kreditkartennummern gestohlen durch SQL Injection.'],
        ],
        'apt_nation_states' => [
            ['id' => 'an_1', 'year' => 2010, 'title' => 'Stuxnet', 'people' => ['NSA', 'Unit 8200'], 'impact' => 'Die erste Cyberwaffe zerstoert physisch iranische Uranzentrifugen.'],
            ['id' => 'an_2', 'year' => 2013, 'title' => 'Snowden-Enthuellungen', 'people' => ['Edward Snowden'], 'impact' => 'NSA-Massenuberwachungsprogramme werden oeffentlich.'],
            ['id' => 'an_3', 'year' => 2016, 'title' => 'Shadow Brokers leaken NSA-Tools', 'people' => ['The Shadow Brokers'], 'impact' => 'NSA-Exploits wie EternalBlue werden veroeffentlicht.'],
            ['id' => 'an_4', 'year' => 2017, 'title' => 'WannaCry & NotPetya', 'people' => ['Lazarus Group', 'GRU'], 'impact' => 'WannaCry legt 200.000 Rechner lahm. NotPetya verursacht 10 Mrd. USD Schaden.'],
        ],
        'supply_chain' => [
            ['id' => 'sc_1', 'year' => 2020, 'title' => 'SolarWinds Hack', 'people' => ['APT29'], 'impact' => 'Kompromittierte Updates betreffen 18.000 Organisationen inkl. US-Regierung.'],
            ['id' => 'sc_2', 'year' => 2021, 'title' => 'Log4Shell', 'people' => ['Chen Zhaojun'], 'impact' => 'Eine Luecke in Log4j betrifft Millionen Java-Anwendungen — CVSS 10.0.'],
            ['id' => 'sc_3', 'year' => 2023, 'title' => 'ChatGPT Prompt Injection', 'people' => [], 'impact' => 'Prompt Injection wird als neue Angriffskategorie erkannt.'],
            ['id' => 'sc_4', 'year' => 2024, 'title' => 'xz Utils Backdoor', 'people' => ['Jia Tan'], 'impact' => 'Eingeschleuster Maintainer platziert Backdoor — entdeckt durch 500ms SSH-Latenz.'],
        ],
        'quantum_threat' => [
            ['id' => 'qt_1', 'year' => 1994, 'title' => 'Shors Algorithmus', 'people' => ['Peter Shor'], 'impact' => 'Quantencomputer kann RSA und ECC in Polynomialzeit brechen.'],
            ['id' => 'qt_2', 'year' => 2024, 'title' => 'NIST Post-Quantum Standards', 'people' => ['NIST'], 'impact' => 'FIPS 203/204/205 standardisieren ML-KEM, ML-DSA und SLH-DSA.'],
            ['id' => 'qt_3', 'year' => 2025, 'title' => 'Harvest Now, Decrypt Later', 'people' => [], 'impact' => 'Geheimdienste sammeln verschluesselte Daten fuer kuenftige Quantencomputer.'],
            ['id' => 'qt_4', 'year' => 2030, 'title' => 'Quantum Key Distribution', 'people' => [], 'impact' => 'Quantenschluesselaustausch verspricht physikalisch sichere Kommunikation.'],
        ],
    ];

    private EpochProgressMapper $progressMapper;
    private IDBConnection $db;
    /** @phpstan-ignore-next-line — Reserved for Gemini narrator integration */
    private LoggerInterface $logger;
    /** @phpstan-ignore-next-line — Reserved for content_language setting */
    private IConfig $config;
    /** @phpstan-ignore-next-line — Reserved for rate-limiting */
    private ICacheFactory $cacheFactory;

    public function __construct(
        EpochProgressMapper $progressMapper,
        IDBConnection       $db,
        LoggerInterface     $logger,
        IConfig             $config,
        ICacheFactory       $cacheFactory
    ) {
        $this->progressMapper = $progressMapper;
        $this->db             = $db;
        $this->logger         = $logger;
        $this->config         = $config;
        $this->cacheFactory   = $cacheFactory;
    }

    // ─── Epoch Info ──────────────────────────────────────────────────────────

    /**
     * List all 7 epochs with metadata.
     *
     * @return array<string, array>
     */
    public function listEpochs(): array {
        return self::EPOCHS;
    }

    // ─── User Progress ──────────────────────────────────────────────────────

    /**
     * Get all epoch progress records for a user + computed totals.
     *
     * @return array{epochs: array, totalScore: int, completedCount: int}
     */
    public function getUserProgress(string $userId): array {
        $records = $this->progressMapper->findAllByUser($userId);
        $totalScore = 0;
        $completedCount = 0;
        $epochData = [];

        foreach ($records as $record) {
            $epochData[] = [
                'epochId'        => $record->getEpochId(),
                'characterClass' => $record->getCharacterClass(),
                'score'          => $record->getScore(),
                'totalQuestions'  => $record->getTotalQuestions(),
                'correctAnswers' => $record->getCorrectAnswers(),
                'status'         => $record->getStatus(),
                'museumViewed'   => $record->getMuseumViewedDecoded(),
                'updatedAt'      => $record->getUpdatedAt(),
            ];
            $totalScore += $record->getScore();
            if ($record->getStatus() === 'completed') {
                $completedCount++;
            }
        }

        return [
            'epochs'         => $epochData,
            'totalScore'     => $totalScore,
            'completedCount' => $completedCount,
        ];
    }

    // ─── Epoch Lifecycle ─────────────────────────────────────────────────────

    /**
     * Start (or restart) an epoch for the current user.
     *
     * @return array{epoch: array, progress: array, firstFacts: array}
     */
    public function startEpoch(string $userId, string $epochId, string $characterClass): array {
        // Validate epoch
        if (!isset(self::EPOCHS[$epochId])) {
            throw new \InvalidArgumentException('Unknown epoch: ' . $epochId);
        }
        // Validate class
        if (!in_array($characterClass, self::VALID_CLASSES, true)) {
            throw new \InvalidArgumentException('Invalid character class: ' . $characterClass);
        }

        $now = time();
        $existing = $this->progressMapper->findByUserAndEpoch($userId, $epochId);

        if ($existing !== null) {
            // Reset for replay
            $existing->setCharacterClass($characterClass);
            $existing->setCurrentScene(null);
            $existing->setScore(0);
            $existing->setTotalQuestions(0);
            $existing->setCorrectAnswers(0);
            $existing->setStatus('in_progress');
            $existing->setMuseumViewed(null);
            $existing->setUpdatedAt($now);
            $this->progressMapper->update($existing);
            $progress = $existing;
        } else {
            $progress = new EpochProgress();
            $progress->setUserId($userId);
            $progress->setEpochId($epochId);
            $progress->setCharacterClass($characterClass);
            $progress->setCurrentScene(null);
            $progress->setScore(0);
            $progress->setTotalQuestions(0);
            $progress->setCorrectAnswers(0);
            $progress->setStatus('in_progress');
            $progress->setMuseumViewed(null);
            $progress->setCreatedAt($now);
            $progress->setUpdatedAt($now);
            /** @var EpochProgress $progress */
            $progress = $this->progressMapper->insert($progress);
        }

        $epoch = self::EPOCHS[$epochId];
        $facts = self::MUSEUM_FACTS[$epochId];

        return [
            'epoch'      => $epoch,
            'progress'   => $this->serializeProgress($progress),
            'firstFacts' => array_slice($facts, 0, 2), // Preview first 2 facts
        ];
    }

    // ─── Museum ──────────────────────────────────────────────────────────────

    /**
     * Get museum facts for an epoch.
     *
     * @return array{facts: array, viewed: string[]}
     */
    public function getMuseumFacts(string $userId, string $epochId): array {
        if (!isset(self::EPOCHS[$epochId])) {
            throw new \InvalidArgumentException('Unknown epoch: ' . $epochId);
        }

        $facts = self::MUSEUM_FACTS[$epochId];
        $progress = $this->progressMapper->findByUserAndEpoch($userId, $epochId);
        $viewed = $progress !== null ? $progress->getMuseumViewedDecoded() : [];

        return [
            'facts'  => $facts,
            'viewed' => $viewed,
        ];
    }

    /**
     * Mark a museum fact as viewed.
     */
    public function markMuseumViewed(string $userId, string $epochId, string $factId): array {
        if (!isset(self::EPOCHS[$epochId])) {
            throw new \InvalidArgumentException('Unknown epoch: ' . $epochId);
        }

        $progress = $this->progressMapper->findByUserAndEpoch($userId, $epochId);
        if ($progress === null) {
            throw new \RuntimeException('No progress record for epoch: ' . $epochId);
        }

        $viewed = $progress->getMuseumViewedDecoded();
        if (!in_array($factId, $viewed, true)) {
            $viewed[] = $factId;
            $progress->setMuseumViewed(json_encode(array_values($viewed)));
            $progress->setUpdatedAt(time());
            $this->progressMapper->update($progress);
        }

        return ['viewed' => $viewed];
    }

    // ─── Skill Check ─────────────────────────────────────────────────────────

    /**
     * Get skill-check questions for an epoch, filtered by poolFilter tag.
     *
     * @return array{questions: array, passThreshold: int, affinity: string}
     */
    public function getSkillCheck(string $userId, string $epochId): array {
        if (!isset(self::EPOCHS[$epochId])) {
            throw new \InvalidArgumentException('Unknown epoch: ' . $epochId);
        }

        $progress = $this->progressMapper->findByUserAndEpoch($userId, $epochId);
        if ($progress === null) {
            throw new \RuntimeException('Start epoch before taking skill check');
        }

        $characterClass = $progress->getCharacterClass();
        $affinity = $this->affinityModifier($characterClass, $epochId);
        $affinityLabel = $this->affinityLabel($characterClass, $epochId);

        // Determine pass threshold based on affinity
        // bonus = 3/5, neutral = 4/5, penalty = 4/5 (same threshold but harder questions)
        $passThreshold = ($affinityLabel === 'bonus') ? 3 : 4;

        $poolFilter = self::EPOCHS[$epochId]['poolFilter'];
        $questions = $this->fetchFilteredQuestions($poolFilter, self::SKILL_CHECK_COUNT, $affinity);

        return [
            'questions'     => $questions,
            'passThreshold' => $passThreshold,
            'affinity'      => $affinityLabel,
            'epochId'       => $epochId,
        ];
    }

    /**
     * Submit skill-check answers and grade them.
     *
     * @param array<array{question_id: int, answer_id: int}> $answers
     * @return array{score: int, correct: int, total: int, passed: bool, affinityEffect: string, status: string}
     */
    public function submitSkillCheck(string $userId, string $epochId, array $answers): array {
        if (!isset(self::EPOCHS[$epochId])) {
            throw new \InvalidArgumentException('Unknown epoch: ' . $epochId);
        }

        $progress = $this->progressMapper->findByUserAndEpoch($userId, $epochId);
        if ($progress === null) {
            throw new \RuntimeException('Start epoch before submitting skill check');
        }

        // Validate answers array (limit to 20 for safety)
        if (count($answers) === 0) {
            throw new \InvalidArgumentException('answers array must not be empty');
        }
        if (count($answers) > 20) {
            throw new \InvalidArgumentException('Too many answers (max 20)');
        }

        // Grade answers
        $correct = 0;
        $total = count($answers);

        foreach ($answers as $a) {
            $questionId = (int)($a['question_id'] ?? 0);
            $answerId = (int)($a['answer_id'] ?? 0);
            if ($questionId <= 0 || $answerId <= 0) {
                continue;
            }
            if ($this->isAnswerCorrect($questionId, $answerId)) {
                $correct++;
            }
        }

        // Determine pass threshold
        $characterClass = $progress->getCharacterClass();
        $affinityLabel = $this->affinityLabel($characterClass, $epochId);
        $passThreshold = ($affinityLabel === 'bonus') ? 3 : 4;

        $passed = $correct >= $passThreshold;

        // Calculate score: base points + affinity bonus
        $scorePerCorrect = 100;
        $affinityBonus = ($affinityLabel === 'bonus') ? 1.2 : (($affinityLabel === 'penalty') ? 0.8 : 1.0);
        $score = (int)round($correct * $scorePerCorrect * $affinityBonus);

        // Update progress
        $progress->setScore($progress->getScore() + $score);
        $progress->setTotalQuestions($progress->getTotalQuestions() + $total);
        $progress->setCorrectAnswers($progress->getCorrectAnswers() + $correct);
        if ($passed) {
            $progress->setStatus('completed');
        }
        $progress->setUpdatedAt(time());
        $this->progressMapper->update($progress);

        return [
            'score'          => $score,
            'correct'        => $correct,
            'total'          => $total,
            'passed'         => $passed,
            'passThreshold'  => $passThreshold,
            'affinityEffect' => $affinityLabel,
            'status'         => $progress->getStatus(),
        ];
    }

    // ─── Overall Score ───────────────────────────────────────────────────────

    /**
     * Aggregate scores across all completed epochs.
     *
     * @return array{totalScore: int, completedEpochs: int, totalEpochs: int}
     */
    public function getOverallScore(string $userId): array {
        $records = $this->progressMapper->findAllByUser($userId);
        $totalScore = 0;
        $completed = 0;

        foreach ($records as $record) {
            $totalScore += $record->getScore();
            if ($record->getStatus() === 'completed') {
                $completed++;
            }
        }

        return [
            'totalScore'      => $totalScore,
            'completedEpochs' => $completed,
            'totalEpochs'     => count(self::EPOCHS),
        ];
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Get affinity modifier as float: 0.8 (bonus=easier), 1.0 (neutral), 1.2 (penalty=harder).
     * Used as difficulty modifier for question fetching.
     */
    private function affinityModifier(string $class, string $epochId): float {
        $label = $this->affinityLabel($class, $epochId);
        return match ($label) {
            'bonus'   => 0.8,
            'penalty' => 1.2,
            default   => 1.0,
        };
    }

    /**
     * Get affinity label: bonus, neutral, or penalty.
     */
    private function affinityLabel(string $class, string $epochId): string {
        $map = self::CLASS_AFFINITIES[$class] ?? null;
        if ($map === null) {
            return 'neutral';
        }
        if (in_array($epochId, $map['affinity'], true)) {
            return 'bonus';
        }
        if (in_array($epochId, $map['weak'], true)) {
            return 'penalty';
        }
        return 'neutral';
    }

    /**
     * Fetch random questions from pools matching the given filter tag.
     * Uses LIKE-based keyword filter against pool name/description
     * (same pattern as StoryEngineService::fetchFilteredQuestions).
     *
     * @return array<array{id: int, text: string, image_path: string|null, answers: array}>
     */
    private function fetchFilteredQuestions(?string $filter, int $count, float $difficultyModifier): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('q.id', 'q.text', 'q.image_path')
           ->from('learning_questions', 'q');

        // Apply filter: join on pool and match by keyword
        if ($filter !== null && $filter !== '') {
            $keyword = '%' . $this->db->escapeLikeParameter($filter) . '%';
            $qb->innerJoin('q', 'learning_pools', 'p', $qb->expr()->eq('q.pool_id', 'p.id'))
               ->where(
                   $qb->expr()->orX(
                       $qb->expr()->like('p.name',        $qb->createNamedParameter($keyword)),
                       $qb->expr()->like('p.description', $qb->createNamedParameter($keyword))
                   )
               );
        }

        // Difficulty: fetch extra and offset for harder/easier selection
        $extra = (int)round(abs($difficultyModifier - 1.0) * 4);
        $qb->orderBy($qb->createFunction('RANDOM()'))
           ->setMaxResults($count + $extra);

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [];
        }

        // Difficulty offset: penalty = pick from further in (harder), bonus = pick from start (easier)
        $offset = 0;
        if ($difficultyModifier > 1.0) {
            $offset = min($extra, max(0, count($rows) - $count));
        }
        $rows = array_slice($rows, $offset, $count);

        // Load answers for each question
        $questions = [];
        foreach ($rows as $row) {
            $qId = (int)$row['id'];
            $answers = $this->loadAnswersForQuestion($qId);
            if (empty($answers)) {
                continue;
            }
            $questions[] = [
                'id'         => $qId,
                'text'       => $row['text'],
                'image_path' => $row['image_path'] ?? null,
                'answers'    => $answers,
            ];
        }

        return $questions;
    }

    /**
     * Load shuffled answers for a question (without is_correct flag for client).
     *
     * @return array<array{id: int, text: string}>
     */
    private function loadAnswersForQuestion(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->orderBy($qb->createFunction('RANDOM()'));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn(array $r) => [
            'id'   => (int)$r['id'],
            'text' => $r['text'],
        ], $rows);
    }

    /**
     * Check if an answer is correct.
     */
    private function isAnswerCorrect(int $questionId, int $answerId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return false;
        }
        return (bool)$row['is_correct'];
    }

    /**
     * Serialize an EpochProgress entity to an array.
     */
    private function serializeProgress(EpochProgress $progress): array {
        return [
            'id'             => $progress->getId(),
            'epochId'        => $progress->getEpochId(),
            'characterClass' => $progress->getCharacterClass(),
            'score'          => $progress->getScore(),
            'totalQuestions'  => $progress->getTotalQuestions(),
            'correctAnswers' => $progress->getCorrectAnswers(),
            'status'         => $progress->getStatus(),
            'museumViewed'   => $progress->getMuseumViewedDecoded(),
            'updatedAt'      => $progress->getUpdatedAt(),
        ];
    }
}
