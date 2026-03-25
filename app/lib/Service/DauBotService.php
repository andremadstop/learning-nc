<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Generates deterministic beginner mistakes for graph-campaign scenes.
 *
 * The output is derived from the node payload so the same scene yields the same
 * DAU-Bot hint/correction challenge across save/resume cycles.
 */
class DauBotService {
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private const TEMPLATE_CATALOG = [
        'default' => [
            [
                'mistake_id' => 'weak_credentials',
                'title' => 'Default Credentials',
                'bot_action' => 'DAU-Bot rolled the service into production with the vendor default password still active.',
                'symptom' => 'The system can be accessed with trivial credentials and shared accounts.',
                'severity' => 'high',
                'recommended_fix' => 'Rotate all credentials, enforce MFA, and replace shared admin access with named accounts.',
                'error_id' => 'default_passwords',
                'fix_id' => 'rotate_credentials',
                'error_options' => [
                    ['id' => 'default_passwords', 'label' => 'Default passwords are still active'],
                    ['id' => 'missing_dns_record', 'label' => 'A DNS record is missing'],
                    ['id' => 'unused_vlan', 'label' => 'An unused VLAN is configured'],
                ],
                'fix_options' => [
                    ['id' => 'rotate_credentials', 'label' => 'Rotate credentials and enforce MFA'],
                    ['id' => 'open_firewall', 'label' => 'Open more inbound firewall rules'],
                    ['id' => 'disable_logging', 'label' => 'Disable logging to reduce noise'],
                ],
            ],
            [
                'mistake_id' => 'shared_admin',
                'title' => 'Shared Admin Account',
                'bot_action' => 'DAU-Bot solved the onboarding problem by giving the whole team the same admin account.',
                'symptom' => 'Actions are no longer attributable and offboarding becomes risky.',
                'severity' => 'medium',
                'recommended_fix' => 'Create personal admin accounts, assign roles, and remove shared credentials.',
                'error_id' => 'shared_admin_account',
                'fix_id' => 'named_admin_accounts',
                'error_options' => [
                    ['id' => 'shared_admin_account', 'label' => 'Everyone uses the same admin account'],
                    ['id' => 'expired_certificate', 'label' => 'The TLS certificate expired'],
                    ['id' => 'missing_static_route', 'label' => 'A static route is missing'],
                ],
                'fix_options' => [
                    ['id' => 'named_admin_accounts', 'label' => 'Issue named admin accounts and remove the shared one'],
                    ['id' => 'reboot_switch', 'label' => 'Reboot the switch immediately'],
                    ['id' => 'allow_any_any', 'label' => 'Add an allow any-any firewall rule'],
                ],
            ],
        ],
        'firewall' => [
            [
                'mistake_id' => 'allow_any_any',
                'title' => 'Allow Any-Any Rule',
                'bot_action' => 'DAU-Bot fixed the outage by inserting an allow any-any rule at the top of the firewall policy.',
                'symptom' => 'Traffic flows again, but segmentation and least privilege are gone.',
                'severity' => 'critical',
                'recommended_fix' => 'Remove the broad rule and replace it with narrowly scoped service-specific entries plus an implicit deny.',
                'error_id' => 'allow_any_any',
                'fix_id' => 'restrict_firewall_rule',
                'error_options' => [
                    ['id' => 'allow_any_any', 'label' => 'A broad allow any-any rule was added'],
                    ['id' => 'missing_ptr_record', 'label' => 'A PTR record is missing'],
                    ['id' => 'oversized_mtu', 'label' => 'The MTU is too large'],
                ],
                'fix_options' => [
                    ['id' => 'restrict_firewall_rule', 'label' => 'Replace it with scoped rules and keep implicit deny'],
                    ['id' => 'disable_nat', 'label' => 'Disable NAT globally'],
                    ['id' => 'publish_admin_port', 'label' => 'Expose the admin interface publicly'],
                ],
            ],
            [
                'mistake_id' => 'open_admin_plane',
                'title' => 'Admin Plane Exposed',
                'bot_action' => 'DAU-Bot opened the firewall management port to the whole internet so support can reach it from anywhere.',
                'symptom' => 'The management plane is reachable from untrusted networks.',
                'severity' => 'high',
                'recommended_fix' => 'Limit management access to the admin network or VPN and require MFA.',
                'error_id' => 'admin_port_exposed',
                'fix_id' => 'limit_admin_network',
                'error_options' => [
                    ['id' => 'admin_port_exposed', 'label' => 'The firewall admin port is exposed to the internet'],
                    ['id' => 'dns_cache_poisoning', 'label' => 'DNS cache poisoning is occurring'],
                    ['id' => 'stale_arp_entry', 'label' => 'An ARP entry is stale'],
                ],
                'fix_options' => [
                    ['id' => 'limit_admin_network', 'label' => 'Restrict admin access to VPN or the admin subnet'],
                    ['id' => 'increase_timeout', 'label' => 'Increase the session timeout'],
                    ['id' => 'close_https', 'label' => 'Disable HTTPS everywhere'],
                ],
            ],
        ],
        'dns' => [
            [
                'mistake_id' => 'broken_mx',
                'title' => 'Broken Mail Routing',
                'bot_action' => 'DAU-Bot cleaned up the zone file and accidentally removed the MX record because "mail works with A records too".',
                'symptom' => 'Mail delivery becomes unreliable or stops entirely.',
                'severity' => 'high',
                'recommended_fix' => 'Restore the MX record with the correct priority and verify the target host resolves.',
                'error_id' => 'missing_mx_record',
                'fix_id' => 'restore_mx_record',
                'error_options' => [
                    ['id' => 'missing_mx_record', 'label' => 'The MX record was removed or points nowhere'],
                    ['id' => 'firewall_loop', 'label' => 'A firewall loop was introduced'],
                    ['id' => 'stuck_spanning_tree', 'label' => 'Spanning tree is blocking the port'],
                ],
                'fix_options' => [
                    ['id' => 'restore_mx_record', 'label' => 'Restore the MX record and verify the target host'],
                    ['id' => 'disable_recursive_dns', 'label' => 'Disable recursive DNS everywhere'],
                    ['id' => 'rename_domain', 'label' => 'Rename the domain immediately'],
                ],
            ],
            [
                'mistake_id' => 'zone_transfer_open',
                'title' => 'Open Zone Transfer',
                'bot_action' => 'DAU-Bot made zone transfers public so "secondary DNS will always work".',
                'symptom' => 'Attackers can enumerate internal hostnames and services.',
                'severity' => 'medium',
                'recommended_fix' => 'Restrict zone transfers to authorized secondaries and TSIG-protect them where possible.',
                'error_id' => 'public_zone_transfer',
                'fix_id' => 'restrict_zone_transfer',
                'error_options' => [
                    ['id' => 'public_zone_transfer', 'label' => 'Zone transfers are open to everyone'],
                    ['id' => 'expired_radius_secret', 'label' => 'The RADIUS secret expired'],
                    ['id' => 'wrong_default_gateway', 'label' => 'The default gateway is wrong'],
                ],
                'fix_options' => [
                    ['id' => 'restrict_zone_transfer', 'label' => 'Allow transfers only to trusted secondaries'],
                    ['id' => 'increase_ttl', 'label' => 'Increase every DNS TTL to seven days'],
                    ['id' => 'remove_ns_record', 'label' => 'Remove the NS record'],
                ],
            ],
        ],
        'routing' => [
            [
                'mistake_id' => 'default_route_loop',
                'title' => 'Default Route Loop',
                'bot_action' => 'DAU-Bot pointed the default route at a neighbor that already points back here.',
                'symptom' => 'Packets bounce between routers until TTL expires.',
                'severity' => 'high',
                'recommended_fix' => 'Correct the next hop, add route validation, and monitor for TTL exceeded events.',
                'error_id' => 'route_loop',
                'fix_id' => 'correct_next_hop',
                'error_options' => [
                    ['id' => 'route_loop', 'label' => 'A routing loop was introduced'],
                    ['id' => 'dnssec_failure', 'label' => 'DNSSEC validation failed'],
                    ['id' => 'ssh_key_expired', 'label' => 'The SSH key expired'],
                ],
                'fix_options' => [
                    ['id' => 'correct_next_hop', 'label' => 'Fix the next hop and validate route advertisements'],
                    ['id' => 'disable_ttl', 'label' => 'Disable TTL checks'],
                    ['id' => 'broadcast_nat', 'label' => 'Broadcast NAT translations to all routers'],
                ],
            ],
            [
                'mistake_id' => 'blackhole_route',
                'title' => 'Blackhole Route',
                'bot_action' => 'DAU-Bot replaced a specific route with a broad summary that points to a null interface.',
                'symptom' => 'A whole subnet becomes unreachable even though the core network stays up.',
                'severity' => 'medium',
                'recommended_fix' => 'Restore the specific route and verify summarization boundaries.',
                'error_id' => 'blackhole_route',
                'fix_id' => 'restore_specific_route',
                'error_options' => [
                    ['id' => 'blackhole_route', 'label' => 'Traffic is routed into a blackhole'],
                    ['id' => 'missing_vlan_tag', 'label' => 'A VLAN tag is missing'],
                    ['id' => 'wep_enabled', 'label' => 'WEP is enabled'],
                ],
                'fix_options' => [
                    ['id' => 'restore_specific_route', 'label' => 'Restore the specific route and review summarization'],
                    ['id' => 'raise_metric', 'label' => 'Raise the metric on all routes'],
                    ['id' => 'delete_arp_cache', 'label' => 'Delete the ARP cache on every host'],
                ],
            ],
        ],
        'nat' => [
            [
                'mistake_id' => 'full_cone_everything',
                'title' => 'Overexposed NAT',
                'bot_action' => 'DAU-Bot exposed an internal host with a broad one-to-one NAT because remote access was needed quickly.',
                'symptom' => 'An internal service is reachable from the internet without segmentation.',
                'severity' => 'high',
                'recommended_fix' => 'Replace it with scoped port forwarding or VPN access and review exposed services.',
                'error_id' => 'internal_host_exposed',
                'fix_id' => 'scope_port_forwarding',
                'error_options' => [
                    ['id' => 'internal_host_exposed', 'label' => 'An internal host was exposed broadly'],
                    ['id' => 'missing_dns_ptr', 'label' => 'A PTR record is missing'],
                    ['id' => 'ntp_skew', 'label' => 'NTP drift is too high'],
                ],
                'fix_options' => [
                    ['id' => 'scope_port_forwarding', 'label' => 'Reduce exposure to specific ports or require VPN'],
                    ['id' => 'disable_nat_logging', 'label' => 'Disable NAT logging'],
                    ['id' => 'reset_switch', 'label' => 'Factory-reset the switch'],
                ],
            ],
            [
                'mistake_id' => 'pat_collision',
                'title' => 'PAT Collision',
                'bot_action' => 'DAU-Bot reused a translated port range that is already in use by other clients.',
                'symptom' => 'Sessions flap or map to the wrong internal client.',
                'severity' => 'medium',
                'recommended_fix' => 'Expand the PAT pool or randomize translations to avoid collisions.',
                'error_id' => 'pat_collision',
                'fix_id' => 'expand_pat_pool',
                'error_options' => [
                    ['id' => 'pat_collision', 'label' => 'PAT translations collide'],
                    ['id' => 'radius_timeout', 'label' => 'RADIUS timed out'],
                    ['id' => 'loop_guard', 'label' => 'Loop guard disabled a port'],
                ],
                'fix_options' => [
                    ['id' => 'expand_pat_pool', 'label' => 'Expand or randomize the translated port pool'],
                    ['id' => 'disable_pat', 'label' => 'Disable PAT entirely'],
                    ['id' => 'raise_dns_ttl', 'label' => 'Raise DNS TTLs'],
                ],
            ],
        ],
    ];

    /**
     * Build a DAU-Bot hint payload for a node.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    public function buildSceneSuggestion(array $node, array $stateBag): array {
        $template = $this->resolveTemplate($node, $stateBag);

        return [
            'mistake_id' => $template['mistake_id'],
            'title' => $template['title'],
            'bot_action' => $template['bot_action'],
            'symptom' => $template['symptom'],
            'severity' => $template['severity'],
            'recommended_fix' => $template['recommended_fix'],
            'scene_type' => $this->resolveSceneType($node),
            'simulator_type' => $this->resolveSimulatorType($node),
        ];
    }

    /**
     * Build a bot-correction challenge for a node.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    public function buildBotCorrectionScenario(array $node, array $stateBag): array {
        $customScenario = $this->buildCustomCorrectionScenario($node);
        if ($customScenario !== null) {
            return $customScenario;
        }

        $template = $this->resolveTemplate($node, $stateBag);
        $nodeId = (string)($node['id'] ?? 'graph-node');

        return [
            'scenario_id' => 'bot-correction-' . $nodeId,
            'mistake_id' => $template['mistake_id'],
            'prompt' => (string)($node['prompt'] ?? 'Review the DAU-Bot action, identify the mistake, and choose the best correction.'),
            'bot_action' => $template['bot_action'],
            'symptom' => $template['symptom'],
            'error_options' => $template['error_options'],
            'fix_options' => $template['fix_options'],
            'expected_error_id' => $template['error_id'],
            'expected_fix_id' => $template['fix_id'],
            'pass_flag' => (string)($node['pass_flag'] ?? ('bot_corrected_' . $nodeId)),
            'scene_type' => $this->resolveSceneType($node),
            'simulator_type' => $this->resolveSimulatorType($node),
        ];
    }

    /**
     * Remove validation-only keys before returning the scenario to the frontend.
     *
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    public function toPublicBotCorrectionScenario(array $scenario): array {
        unset($scenario['expected_error_id'], $scenario['expected_fix_id']);

        return $scenario;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function buildCustomCorrectionScenario(array $node): ?array {
        $config = $node['bot_correction'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $errorOptions = $this->normalizeOptions($config['error_options'] ?? []);
        $fixOptions = $this->normalizeOptions($config['fix_options'] ?? []);
        $expectedErrorId = (string)($config['expected_error_id'] ?? '');
        $expectedFixId = (string)($config['expected_fix_id'] ?? '');

        if ($errorOptions === [] || $fixOptions === [] || $expectedErrorId === '' || $expectedFixId === '') {
            return null;
        }

        $nodeId = (string)($node['id'] ?? 'graph-node');

        return [
            'scenario_id' => (string)($config['scenario_id'] ?? ('bot-correction-' . $nodeId)),
            'mistake_id' => (string)($config['mistake_id'] ?? ('bot-mistake-' . $nodeId)),
            'prompt' => (string)($config['prompt'] ?? 'Review the DAU-Bot action, identify the mistake, and choose the best correction.'),
            'bot_action' => (string)($config['bot_action'] ?? ''),
            'symptom' => (string)($config['symptom'] ?? ''),
            'error_options' => $errorOptions,
            'fix_options' => $fixOptions,
            'expected_error_id' => $expectedErrorId,
            'expected_fix_id' => $expectedFixId,
            'pass_flag' => (string)($config['pass_flag'] ?? ($node['pass_flag'] ?? ('bot_corrected_' . $nodeId))),
            'scene_type' => $this->resolveSceneType($node),
            'simulator_type' => $this->resolveSimulatorType($node),
        ];
    }

    /**
     * @param mixed $options
     * @return list<array{id: string, label: string}>
     */
    private function normalizeOptions($options): array {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $id = trim((string)($option['id'] ?? ''));
            $label = trim((string)($option['label'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $stateBag
     * @return array<string, mixed>
     */
    private function resolveTemplate(array $node, array $stateBag): array {
        $catalogKey = $this->resolveCatalogKey($node);
        $templates = self::TEMPLATE_CATALOG[$catalogKey] ?? self::TEMPLATE_CATALOG['default'];
        $seed = (string)($node['id'] ?? 'graph-node') . '|' . $catalogKey;
        $index = $this->deterministicIndex($seed, count($templates));

        return $templates[$index];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveCatalogKey(array $node): string {
        $simulatorType = $this->resolveSimulatorType($node);
        if ($simulatorType !== null && isset(self::TEMPLATE_CATALOG[$simulatorType])) {
            return $simulatorType;
        }

        $sceneType = $this->resolveSceneType($node);
        if (isset(self::TEMPLATE_CATALOG[$sceneType])) {
            return $sceneType;
        }

        return 'default';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveSceneType(array $node): string {
        return strtolower(trim((string)($node['type'] ?? 'default'))) ?: 'default';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveSimulatorType(array $node): ?string {
        $simulator = $node['simulator'] ?? $node['simulation'] ?? null;
        if (!is_array($simulator)) {
            return null;
        }

        $type = strtolower(trim((string)($simulator['type'] ?? '')));

        return $type !== '' ? $type : null;
    }

    private function deterministicIndex(string $seed, int $count): int {
        if ($count <= 1) {
            return 0;
        }

        return (int)(sprintf('%u', crc32($seed)) % $count);
    }
}
