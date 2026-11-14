<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IL10N;

/**
 * GitHub #48: column headers and status values of the course CSV exports (certificate report,
 * at-risk list, class summary) in the language of the person who exports them. They used to be
 * fixed German or English, and the at-risk reasons were German sentences.
 *
 * Keys follow the German-source convention. No key contains a percent sign: Nextcloud runs every
 * translation through vsprintf, where a bare "%" throws. Percent units are appended outside t().
 */
class CsvExportL10n {
    /** Lets Excel read the file as UTF-8 (names and Cyrillic or Arabic headers). */
    public const BOM = "\xEF\xBB\xBF";
    public const CONTENT_TYPE = 'text/csv; charset=utf-8';

    private ?IL10N $l;

    public function __construct(?IL10N $l = null) {
        $this->l = $l;
    }

    /** @param list<string> $params */
    private function t(string $text, array $params = []): string {
        if ($this->l !== null) {
            return $this->l->t($text, $params);
        }
        return $params === [] ? $text : vsprintf($text, $params);
    }

    /** @return list<string> */
    public function certReportHeader(): array {
        return [
            $this->t('Name'),
            $this->t('Bestanden am'),
            $this->t('Ergebnis') . ' (%)',
            $this->t('Gültig bis'),
            $this->t('Verifizierungs-ID'),
            $this->t('Status'),
        ];
    }

    public function certStatus(bool $expired): string {
        return $expired ? $this->t('Abgelaufen (Zertifikatsstatus)') : $this->t('Gültig (Zertifikatsstatus)');
    }

    /** @return list<string> */
    public function atRiskHeader(): array {
        return [
            $this->t('Name'),
            $this->t('Risikostufe'),
            $this->t('Kritische Karten'),
            $this->t('Risikogründe'),
            $this->t('Trefferquote') . ' (%)',
            $this->t('Zuletzt aktiv'),
        ];
    }

    public function riskLevel(string $level): string {
        return match ($level) {
            'high' => $this->t('Hohes Risiko'),
            'medium' => $this->t('Mittleres Risiko'),
            default => $level,
        };
    }

    /**
     * The reasons of one at-risk entry from its codes, as the participants tab shows them. An
     * entry without codes (older service) keeps its stored sentences.
     *
     * @param array<string, mixed> $student
     */
    public function riskReasons(array $student): string {
        $codes = $student['risk_reason_codes'] ?? null;
        if (!is_array($codes)) {
            return implode('; ', array_map('strval', (array)($student['risk_reasons'] ?? [])));
        }
        $labels = [];
        foreach ($codes as $entry) {
            $code = (string)($entry['code'] ?? '');
            $n = (string)(int)($entry['n'] ?? 0);
            $labels[] = match ($code) {
                'inactive_days' => str_replace('{n}', $n, $this->t('Inaktiv seit {n} Tagen')),
                'never_active' => $this->t('Noch nie aktiv gewesen'),
                'low_accuracy' => $this->t('Trefferquote: %s', [$n . ' %']),
                'critical_cards' => str_replace('{n}', $n, $this->t('Kritische Karten: {n}')),
                'box_one_share' => $this->t('Karten in Box 1: %s', [$n . ' %']),
                'streak_lost' => str_replace('{n}', $n, $this->t('Streak verloren (war {n})')),
                'few_sessions' => str_replace('{n}', $n, $this->t('Sessions in 14 Tagen: {n}')),
                default => $code,
            };
        }
        return implode('; ', $labels);
    }

    /** @return list<string> */
    public function classSummaryHeader(): array {
        return [
            $this->t('Benutzer-ID'),
            $this->t('XP'),
            $this->t('Level'),
            $this->t('Trefferquote') . ' (%)',
            $this->t('Beherrschung') . ' (%)',
            $this->t('Sessions'),
            $this->t('Streak'),
            $this->t('Abzeichen'),
            $this->t('Gewonnene Duelle'),
            $this->t('Schwarm-Beiträge'),
        ];
    }
}
