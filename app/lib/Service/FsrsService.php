<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

class FsrsService {
    /**
     * FSRS-5 default weights.
     *
     * @var float[]
     */
    private const W = [
        0.4, 0.6, 2.4, 5.8,
        4.93, 0.94, 0.86, 0.01,
        1.49, 0.14, 0.94, 2.18,
        0.05, 0.34, 1.26, 0.29,
        2.61,
    ];

    public function review(float $stability, float $difficulty, int $rating, float $elapsedDays): array {
        $rating = $this->normalizeRating($rating);
        $stability = max(0.1, $stability);
        $difficulty = $this->clampDifficulty($difficulty);
        $elapsedDays = max(0.0, $elapsedDays);

        $retrievability = $this->calculateRetrievability($stability, $elapsedDays);
        $newDifficulty = $this->updateDifficulty($difficulty, $rating);
        $newStability = $this->updateStability($stability, $difficulty, $retrievability, $rating);

        return [
            'stability' => $newStability,
            'difficulty' => $newDifficulty,
            'interval_days' => $this->calculateIntervalDays($newStability),
            'retrievability' => $retrievability,
        ];
    }

    public function initializeFromRating(int $rating): array {
        $rating = $this->normalizeRating($rating);
        $stability = self::W[$rating - 1];
        $difficulty = self::W[4] - exp(self::W[5] * ($rating - 1)) + 1;

        return [
            'stability' => max(0.1, $stability),
            'difficulty' => $this->clampDifficulty($difficulty),
            'interval_days' => $this->calculateIntervalDays($stability),
            'retrievability' => 1.0,
        ];
    }

    public function calculateRetrievability(float $stability, float $elapsedDays): float {
        $stability = max(0.1, $stability);
        $elapsedDays = max(0.0, $elapsedDays);

        return exp(log(0.9) * $elapsedDays / $stability);
    }

    public function calculateIntervalDays(float $stability): int {
        return max(1, (int) round(max(0.1, $stability)));
    }

    private function updateDifficulty(float $difficulty, int $rating): float {
        $newDifficulty = $difficulty - self::W[6] * ($rating - 3);

        return $this->clampDifficulty($newDifficulty);
    }

    private function updateStability(float $stability, float $difficulty, float $retrievability, int $rating): float {
        if ($rating === 1) {
            $resetStability = self::W[11]
                * pow($difficulty, -self::W[12])
                * (pow($stability + 1, self::W[13]) - 1)
                * exp(self::W[14] * (1 - $retrievability));

            return max(0.1, $resetStability);
        }

        $hardPenalty = $rating === 2 ? self::W[15] : 1.0;
        $easyBonus = $rating === 4 ? self::W[16] : 1.0;
        $growth = exp(self::W[8])
            * (11 - $difficulty)
            * pow($stability, -self::W[9])
            * (exp((1 - $retrievability) * self::W[10]) - 1)
            * $hardPenalty
            * $easyBonus;

        return max(0.1, $stability * (1 + $growth));
    }

    private function clampDifficulty(float $difficulty): float {
        return max(0.1, min(1.0, $difficulty));
    }

    private function normalizeRating(int $rating): int {
        if ($rating < 1 || $rating > 4) {
            throw new \InvalidArgumentException('FSRS rating must be between 1 and 4');
        }

        return $rating;
    }
}
