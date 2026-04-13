#!/usr/bin/env php
<?php
declare(strict_types=1);

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\GeminiService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

define('OC_CONSOLE', true);
require_once '/var/www/html/lib/base.php';
\OC_App::loadApp('learning');

if (!class_exists(GeminiService::class)) {
    require_once '/var/www/html/custom_apps/learning/lib/Service/GeminiService.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/QuestionMapper.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/AnswerMapper.php';
}

$poolId = 124;
$dryRun = false;
$lang = 'de';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--en') {
        $lang = 'en';
        continue;
    }
    if (preg_match('/^\d+$/', $arg)) {
        $poolId = (int)$arg;
    }
}

$server = \OC::$server;
$db = $server->get(IDBConnection::class);
$gemini = new GeminiService(
    $server->get(IConfig::class),
    $server->get(ICacheFactory::class),
    $db,
    $server->get(LoggerInterface::class),
    $server->get(\OCA\Learning\Service\LlmService::class)
);
$questionMapper = new QuestionMapper($db);
$answerMapper = new AnswerMapper($db);

$questions = $questionMapper->findByPoolId($poolId);
$targets = array_values(array_filter($questions, static function ($question): bool {
    $row = $question->jsonSerialize();
    return shouldReplaceExplanation((string)($row['explanation'] ?? ''));
}));

fwrite(STDOUT, sprintf(
    "Pool %d: %d Fragen insgesamt, %d Platzhalter-Erklärungen%s\n",
    $poolId,
    count($questions),
    count($targets),
    $dryRun ? ' (Dry Run)' : ''
));

$updated = 0;
$fallbackCount = 0;
$failed = 0;

foreach ($targets as $index => $question) {
    $questionRow = $question->jsonSerialize();
    $questionId = (int)$questionRow['id'];
    $answers = $answerMapper->findByQuestion($questionId);
    $answerRows = array_map(static fn($answer) => $answer->jsonSerialize(), $answers);

    try {
        $explanation = generateExplanation($gemini, $questionRow, $answerRows, $lang);
        $usedFallback = false;
    } catch (\Throwable $e) {
        $explanation = buildFallbackExplanation($questionRow, $answerRows);
        $usedFallback = true;
        fwrite(STDERR, sprintf("[warn] Q%d Gemini fehlgeschlagen: %s\n", $questionId, $e->getMessage()));
    }

    if (shouldReplaceExplanation($explanation)) {
        $explanation = buildFallbackExplanation($questionRow, $answerRows);
        $usedFallback = true;
    }

    $preview = mb_substr($explanation, 0, 120);
    fwrite(STDOUT, sprintf(
        "[%d/%d] Q%d %s%s\n",
        $index + 1,
        count($targets),
        $questionId,
        $dryRun ? 'DRY ' : '',
        $preview
    ));

    if ($dryRun) {
        if ($usedFallback) {
            $fallbackCount++;
        }
        continue;
    }

    try {
        $question->setExplanation($explanation);
        $question->setUpdatedAt(time());
        $questionMapper->createOrUpdate($question);
        $updated++;
        if ($usedFallback) {
            $fallbackCount++;
        }
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, sprintf("[error] Q%d konnte nicht gespeichert werden: %s\n", $questionId, $e->getMessage()));
    }

    usleep(250000);
}

$remainingPlaceholders = 0;
foreach ($questionMapper->findByPoolId($poolId) as $question) {
    $row = $question->jsonSerialize();
    if (shouldReplaceExplanation((string)($row['explanation'] ?? ''))) {
        $remainingPlaceholders++;
    }
}

fwrite(STDOUT, sprintf(
    "Fertig. Aktualisiert: %d | Fallbacks: %d | Fehler: %d | Verbleibende Platzhalter: %d\n",
    $updated,
    $fallbackCount,
    $failed,
    $remainingPlaceholders
));

function shouldReplaceExplanation(string $explanation): bool {
    $trimmed = trim(strip_tags($explanation));
    if ($trimmed === '') {
        return true;
    }

    if ((bool)preg_match('/^Die richtige Antwort ist:\s*.+\.?$/u', $trimmed)) {
        return true;
    }

    // "Correct: A" or similar one-liner without real explanation
    if ((bool)preg_match('/^Correct:\s*[A-F]$/i', $trimmed)) {
        return true;
    }

    // Too short to be a real explanation
    if (mb_strlen($trimmed) < 20) {
        return true;
    }

    return isFallbackTemplateExplanation($trimmed);
}

function isFallbackTemplateExplanation(string $explanation): bool {
    $genericFragments = [
        'Die anderen Optionen klingen zwar plausibel',
        'anderen Mechanismus, ein anderes Protokoll oder den falschen Einsatzbereich',
        'anderen Funktionen, einen anderen Layer oder lassen einen wesentlichen Teil der Anforderung offen',
        'die beschriebene Netzwerksituation',
    ];

    $matchedFragments = 0;
    foreach ($genericFragments as $fragment) {
        if (str_contains($explanation, $fragment)) {
            $matchedFragments++;
        }
    }

    if ($matchedFragments >= 2) {
        return true;
    }

    return (bool)preg_match(
        '/^(?:Diese|diese) Option ist korrekt, weil diese Antwort am besten .+ passt und den beschriebenen Zweck direkt erfüllt\./u',
        $explanation
    );
}

function generateExplanation(GeminiService $gemini, array $questionRow, array $answerRows, string $lang = 'de'): string {
    $questionText = trim((string)($questionRow['text'] ?? ''));
    if ($questionText === '') {
        throw new \RuntimeException('Fragetext fehlt');
    }

    $questionType = (string)($questionRow['question_type'] ?? 'single');
    $difficulty = (string)($questionRow['difficulty'] ?? '');
    $correctAnswers = array_values(array_filter($answerRows, static fn(array $answer): bool => (bool)($answer['is_correct'] ?? false)));
    if ($correctAnswers === []) {
        throw new \RuntimeException('Keine korrekten Antworten gefunden');
    }

    $answerLines = [];
    foreach ($answerRows as $idx => $answer) {
        $correctLabel = $lang === 'en' ? '[correct]' : '[korrekt]';
        $distractorLabel = $lang === 'en' ? '[distractor]' : '[Ablenker]';
        $prefix = ((bool)($answer['is_correct'] ?? false)) ? $correctLabel : $distractorLabel;
        $answerLines[] = sprintf(
            "%d. %s %s",
            $idx + 1,
            $prefix,
            trim((string)($answer['text'] ?? ''))
        );
    }

    if ($lang === 'en') {
        $systemPrompt = implode("\n", [
            'You write technically accurate brief explanations for a learning app covering CompTIA Security+ (SY0-701).',
            'Answer in English.',
            'Format: exactly 1 to 2 sentences, plain text without Markdown, without bullet lists, without introductions like "The correct answer is".',
            'Explain why the correct answer is technically right, and briefly distinguish it from typical distractors when useful.',
            'Stay specific to the question. Maximum 320 characters.',
        ]);
    } else {
        $systemPrompt = implode("\n", [
            'Du schreibst fachlich korrekte Kurz-Erklärungen für eine Learning-App zu CompTIA Network+.',
            'Antworte auf Deutsch.',
            'Form: genau 1 bis 2 Sätze, Klartext ohne Markdown, ohne Aufzählungen, ohne Einleitung wie "Die richtige Antwort ist".',
            'Erkläre, warum die korrekte Antwort fachlich passt, und grenze wenn sinnvoll typische Ablenker kurz ab.',
            'Bleibe konkret zur Frage. Maximal 320 Zeichen.',
        ]);
    }

    $userPrompt = implode("\n", [
        ($lang === 'en' ? 'Question:' : 'Frage:'),
        $questionText,
        '',
        ($lang === 'en' ? 'Type: ' : 'Fragetyp: ') . $questionType,
        ($lang === 'en' ? 'Difficulty: ' : 'Schwierigkeit: ') . ($difficulty !== '' ? $difficulty : ($lang === 'en' ? 'unknown' : 'unbekannt')),
        '',
        ($lang === 'en' ? 'Answer options:' : 'Antwortoptionen:'),
        implode("\n", $answerLines),
    ]);

    $raw = $gemini->generateNote($systemPrompt, $userPrompt);
    return normalizeExplanation($raw);
}

function normalizeExplanation(string $text): string {
    $text = trim($text);
    $text = preg_replace('/^```(?:text|markdown)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = preg_replace('/^Die richtige Antwort ist:\s*/u', '', $text) ?? $text;
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    if (!preg_match('/[.!?]$/u', $text)) {
        $text .= '.';
    }

    return mb_substr($text, 0, 320);
}

function buildFallbackExplanation(array $questionRow, array $answerRows): string {
    $questionText = trim((string)($questionRow['text'] ?? ''));
    $correctAnswers = array_values(array_filter($answerRows, static fn(array $answer): bool => (bool)($answer['is_correct'] ?? false)));
    $correctTexts = array_map(static fn(array $answer): string => trim((string)($answer['text'] ?? '')), $correctAnswers);
    $topic = detectTopicLabel($questionText, $correctTexts);

    if (count($correctTexts) > 1) {
        return normalizeExplanation(
            sprintf(
                '%s sind korrekt, weil sie zusammen die passende Lösung für %s abdecken. Die übrigen Optionen beschreiben entweder andere Funktionen, einen anderen Layer oder lassen einen wesentlichen Teil der Anforderung offen.',
                joinAnswerTexts($correctTexts),
                $topic
            )
        );
    }

    $correctText = $correctTexts[0] ?? 'diese Option';

    return normalizeExplanation(
        sprintf(
            '%s ist korrekt, weil diese Option die Anforderung im beschriebenen Szenario für %s direkt erfüllt. Die anderen Optionen beschreiben hier einen anderen Mechanismus, ein anderes Protokoll oder den falschen Einsatzbereich.',
            $correctText,
            $topic
        )
    );
}

function detectTopicLabel(string $questionText, array $correctTexts): string {
    $haystack = mb_strtolower($questionText . ' ' . implode(' ', $correctTexts));

    // Security+ (SY0-701) topics
    $securityTopics = [
        'threat actors, attack vectors, and social engineering' => ['phishing', 'social engineering', 'threat actor', 'vishing', 'smishing', 'pretexting', 'watering hole', 'typosquatting', 'whaling'],
        'malware and attack techniques' => ['malware', 'ransomware', 'trojan', 'worm', 'rootkit', 'spyware', 'keylogger', 'fileless', 'polymorphic', 'logic bomb'],
        'vulnerability management and scanning' => ['vulnerability', 'cve', 'patch', 'remediation', 'scan', 'penetration test', 'pentest', 'exploit', 'zero-day', 'bug bounty'],
        'identity and access management' => ['authentication', 'authorization', 'mfa', 'sso', 'ldap', 'kerberos', 'saml', 'oauth', 'rbac', 'pam', 'privilege'],
        'cryptography and PKI' => ['encryption', 'certificate', 'pki', 'tls', 'ssl', 'aes', 'rsa', 'hash', 'sha', 'hmac', 'digital signature', 'key exchange', 'diffie-hellman', 'cipher'],
        'network security and architecture' => ['firewall', 'ids', 'ips', 'vpn', 'dmz', 'proxy', 'nat', 'segmentation', 'zero trust', 'sase', 'sd-wan', 'nac'],
        'wireless security' => ['wpa', 'wpa2', 'wpa3', 'wifi', 'wlan', 'ssid', '802.1x', 'eap', 'radius', 'rogue ap', 'evil twin', 'deauthentication'],
        'cloud and virtualization security' => ['cloud', 'iaas', 'paas', 'saas', 'container', 'docker', 'kubernetes', 'hypervisor', 'serverless', 'casb', 'cspm'],
        'incident response and forensics' => ['incident', 'forensic', 'chain of custody', 'eradication', 'containment', 'recovery', 'playbook', 'ioc', 'siem', 'soar'],
        'governance, risk, and compliance' => ['compliance', 'gdpr', 'hipaa', 'pci', 'risk assessment', 'bcp', 'drp', 'rpo', 'rto', 'audit', 'framework', 'nist', 'iso 27001', 'policy'],
        'endpoint and application security' => ['endpoint', 'edr', 'antivirus', 'sandbox', 'application security', 'input validation', 'xss', 'sql injection', 'csrf', 'buffer overflow', 'code review'],
        'physical security' => ['physical', 'badge', 'mantrap', 'bollard', 'cctv', 'biometric', 'fence', 'lock'],
    ];

    // Network+ topics
    $networkTopics = [
        'routing und Weiterleitungsentscheidungen' => ['routing', 'ospf', 'bgp', 'rip', 'eigrp', 'route', 'router'],
        'switching, VLANs und Layer-2-Verhalten' => ['vlan', 'switch', 'trunk', 'stp', 'spanning tree', 'port-security', '802.1q'],
        'dns und Namensauflösung' => ['dns', 'record', 'zone', 'mx', 'cname', 'ptr', 'resolver'],
        'dhcp und Adressvergabe' => ['dhcp', 'lease', 'scope', 'reservation', 'ip helper'],
        'nat und Paketübersetzung' => ['nat', 'pat', 'snat', 'dnat', 'port address translation'],
        'wlan, roaming oder Funkstandards' => ['wlan', 'wifi', 'ssid', 'wpa', 'wpa2', 'wpa3', '802.11', 'access point'],
        'subnetting und IP-Adressierung' => ['subnet', 'cidr', 'prefix', 'ipv4', 'ipv6', 'netzmaske', 'broadcast'],
        'netzwerksicherheit und Zugriffsschutz' => ['firewall', 'acl', 'vpn', 'aaa', 'radius', 'tacacs', 'ids', 'ips', 'zero trust'],
        'kryptografie und Zertifikate' => ['encryption', 'verschlüssel', 'zertifikat', 'certificate', 'tls', 'aes', 'rsa', 'hash'],
        'virtualisierung, cloud oder hochverfügbarkeit' => ['cloud', 'virtual', 'hypervisor', 'cluster', 'ha', 'redundanz'],
        'performance, monitoring oder troubleshooting' => ['latency', 'jitter', 'throughput', 'monitor', 'snmp', 'syslog', 'wireshark', 'troubleshooting'],
    ];

    // Try Security+ topics first (more specific), then Network+
    foreach ($securityTopics as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return $label;
            }
        }
    }

    foreach ($networkTopics as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return $label;
            }
        }
    }

    return 'the described security scenario';
}

function joinAnswerTexts(array $answers): string {
    $answers = array_values(array_filter(array_map('trim', $answers), static fn(string $answer): bool => $answer !== ''));
    if ($answers === []) {
        return 'Diese Antworten';
    }
    if (count($answers) === 1) {
        return $answers[0];
    }
    if (count($answers) === 2) {
        return $answers[0] . ' und ' . $answers[1];
    }

    $last = array_pop($answers);
    return implode(', ', $answers) . ' und ' . $last;
}
