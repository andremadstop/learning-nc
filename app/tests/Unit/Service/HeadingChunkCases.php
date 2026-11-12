<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

/**
 * 2026-11 audit S01/S16/S25: shared Markdown inputs for the three RAG chunkers
 * (RagImportService, ChunkingService, ImportVaultCommand). Each case lists the body text that
 * must reach a chunk and the chapter that chunk must carry.
 */
final class HeadingChunkCases {
    /** @return array<string, array{0: string, 1: list<array{0: string, 1: ?string}>}> */
    public static function cases(): array {
        return [
            'body directly under ATX heading' => [
                "## VLAN\nEin VLAN trennt Broadcast-Domänen auf Layer 2 voneinander.",
                [['Ein VLAN trennt Broadcast-Domänen auf Layer 2 voneinander.', 'VLAN']],
            ],
            'CRLF line endings' => [
                "# Intro\r\n\r\nDer erste Absatz erklärt das Thema ausführlich genug.\r\n\r\n## Teil\r\nDer zweite Absatz steht direkt unter der Überschrift.",
                [
                    ['Der erste Absatz erklärt das Thema ausführlich genug.', 'Intro'],
                    ['Der zweite Absatz steht direkt unter der Überschrift.', 'Teil'],
                ],
            ],
            'body directly under setext heading' => [
                "Subnetting\n----------\nEin /24-Netz hat 254 nutzbare Hostadressen im Netz.",
                [['Ein /24-Netz hat 254 nutzbare Hostadressen im Netz.', 'Subnetting']],
            ],
            'shell comment line inside a paragraph' => [
                "# Firewall\n\nListe die Regeln so auf:\n# iptables -L\nDanach prüfst du die Ketten einzeln.",
                [["Liste die Regeln so auf:\n# iptables -L\nDanach prüfst du die Ketten einzeln.", 'Firewall']],
            ],
            'heading as its own paragraph still tags the next one' => [
                "# Routing\n\nRouter verbinden unterschiedliche Netze miteinander über Layer 3.",
                [['Router verbinden unterschiedliche Netze miteinander über Layer 3.', 'Routing']],
            ],
        ];
    }
}
