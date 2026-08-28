# PBQ simulations — Network+ N10-009 (pool 81)

> Written 2026-03-17, from notes on six original simulation scenarios.
> Translated to English 2026-08-28.

---

## How PBQ questions work

PBQs — performance-based questions — are interactive exam tasks that test practical knowledge
rather than recall. In the real CompTIA exam they run inside a dedicated browser environment.

### Architecture

The app supports five core PBQ subtypes plus several Network+-specific ones:

| Subtype | Component | Description |
|---------|-----------|-------------|
| `cli` | `PbqCli.vue` | Terminal emulator: Cisco IOS, Linux, Windows, SQL or a generic CLI |
| `placement` | `PbqPlacement.vue` | Place devices onto positions in a network topology by clicking |
| `dropdown` | `PbqDropdown.vue` | Multiple-choice questions with sections (tabs) and multi-select |
| `cable` | `PbqCable.vue` | Identify cabling faults through pin mapping — Network+ only |
| `ranking` | `PbqRanking.vue` | Drag items into the correct order (added 2026-05-22) |
| `multi_panel` | `PbqMultiPanel.vue` | Split screen: CLI on the left, dropdown or placement on the right |
| `switch_config` | `PbqSwitchConfig.vue` | Network+ specific: switchport configuration |
| `routing_config` | `PbqRoutingConfig.vue` | Network+ specific: routing-table entry |
| `diagnostic` | `PbqDiagnostic.vue` | Network+ specific: multi-component diagnosis |

### Data flow

```
DB: oc_learning_questions
  ├── question_type = 'pbq'
  ├── pbq_subtype = 'cli' | 'placement' | 'dropdown' | 'cable'
  └── pbq_config = { ... } (JSONB)
         ↓
PbqRenderer.vue  ─── reads pbq_subtype + pbq_config
         ↓
PbqCli.vue / PbqDropdown.vue / PbqPlacement.vue / PbqCable.vue
         ↓
cliStateMachine.js (for CLI: a state machine with per-domain schemas)
         ↓
Scoring: the evaluation[] array (CLI) | positions[].correct (placement) | questions[].correct (dropdown)
```

### The CLI state machine

The CLI simulation uses `app/src/utils/cliStateMachine.js` with five domains:

- `cisco_ios` — modes: `exec` (`SW1>`), `config` (`SW1(config)#`), `config-if` (`SW1(config-if)#`)
- `linux` — `user@host:~$ `
- `windows` — `C:\Users\Administrator> `
- `sql` — `mysql> `
- `generic` — `hostname> `

Command output lives in `pbq_config.command_outputs[terminalName][command]`. Matching is
case-insensitive.

---

## The six simulation scenarios (pool 81)

### Scenario 1 — switch VLAN / LACP configuration

**Question ID:** 12460 | **Subtype:** `cli` | **Terminals:** SW1, SW2

**Description**

An access-layer switch (SW1) has been replaced and needs reconfiguring. PC3 was moved into
VLAN 90 (management) but its port has not been configured yet. The LACP port channel between
SW1 and SW2 needs verifying.

**Configuration**

- SW1 port mapping: Gi0/1→PC1 (VLAN 10), Gi0/2→PC2 (VLAN 20), **Gi0/3→PC3 (VLAN 90, MISSING)**,
  Gi0/4→printer (VLAN 30), Gi0/5→CCTV (VLAN 60)
- LACP: Gi0/7 and Gi0/8 as port channel 1 (mode active, 802.3ad)
- SW2: VLAN 90 on Gi0/2, LACP towards SW1

**Correct solution**

```
SW1# conf t
SW1(config)# interface gi0/3
SW1(config-if)# switchport mode access
SW1(config-if)# switchport access vlan 90
SW1(config-if)# end
SW1# show vlan brief         (verification)
```

**Scoring (5 points)**

- `switchport access vlan 90` on SW1 → 3 points
- `show vlan brief` on SW1 → 1 point
- `show interfaces trunk` on SW2 → 1 point

### Scenario 2 — MAC table and routing diagnosis

**Question ID:** 12465 | **Subtype:** `cli` | **Terminals:** SW1, SW2

**Description**

A newly hired network technician is asked to document the network. Connection problems are
suspected because the MAC tables keep changing. Map the topology by analysing the MAC tables and
the routing information.

**Correct solution**

```
SW1# show mac address-table
SW1# show running-config
SW2# show mac address-table
SW2# show ip route
```

**Key findings**

- SW1's MAC table: seven entries, two MAC addresses appearing on several ports — a possible loop
- SW2: only three entries (healthier); the file server sits in VLAN 10 on Gi0/1
- Routing: default route 0.0.0.0/0 via 10.0.0.1

**Scoring (5 points)**

- `show mac address-table` on SW1 → 2 points
- `show mac address-table` on SW2 → 2 points
- `show running-config` on SW1 → 1 point

### Scenario 3 — router addressing and a routing gap

**Question ID:** 12461 | **Subtype:** `cli` | **Terminals:** Router A, Router B, Router C

**Description**

Users cannot reach file server 2 (10.0.4.x). Investigate the routing between routers A, B and C.

**Network layout**

```
Router A: Gi1=10.0.5.0/24, Gi2=10.0.6.0/24, Gi3=10.0.0.0/22
Router B: Gi1=10.0.4.0/22 (broadcast 10.0.7.255), Gi2=10.0.1.0/24, Gi3=10.0.0.0/24
Router C: Gi1=10.0.0.0/22, Gi2=10.0.4.0/22
```

**Correct diagnosis**

- Router A has **no route** to 10.0.4.0/22 in its table
- `ping 10.0.4.1` from router A fails
- Router B knows 10.0.4.0/22 directly, on Gi1
- Router C has no route to 10.0.5.0/24 or 10.0.6.0/24

**Correct solution:** extend the OSPF configuration on router C so that routes to 10.0.5.0/24
and 10.0.6.0/24 are propagated. Alternatively, add a static route on router A.

**Scoring (6 points)**

- `show ip route` on router A → 2 points
- `show ip route` on router B → 2 points
- `show ip route` on router C → 2 points

### Scenario 4 — network placement (topology design)

**Question ID:** 12468 | **Subtype:** `placement`

**Description**

Design the network topology for a new office building. Four positions (A–D) have to be filled
with the right devices. One switch is already placed.

**Positions and correct devices**

| Position | Description | Correct device |
|----------|-------------|----------------|
| Device A | Internet gateway, directly below the cloud | **Firewall** |
| Device B | LAN interconnect, below the firewall | **Router** |
| Device C | Wireless device in the office area, bottom left | **WAP** |
| Device D | Wireless device in the extension area, bottom right | **Wireless range extender** |

**Reasoning**

- Firewall: the first line of defence towards the internet
- Router: the layer-3 device that joins the segments
- WAP: provides its own SSID, so clients associate with it directly
- Wireless range extender: amplifies and extends an existing wireless network

**Scoring:** `partial` — points per correctly placed device.

### Scenario 5 — APIPA / DHCP failure (dropdown)

**Question ID:** 12472 | **Subtype:** `dropdown`

**Description**

Several workstations (PC-A, PC-B, PC-C) hold addresses in 169.254.x.x/16 and cannot reach the
file server at 192.168.1.100.

**Questions and answers**

| Question | Correct answer | Explanation |
|----------|----------------|-------------|
| Which address range indicates a configuration problem? | **169.254.x.x/16 (the APIPA range)** | APIPA — RFC 3927 — is the Windows/Linux fallback when no DHCP server answers |
| Which service has failed? | **DHCP** | DHCP assigns addresses automatically; without it, clients fall back to APIPA |
| How do you fix it? | **Restart the DHCP server, then `ipconfig /renew`** | The fastest route back; static addresses would only be a workaround |

### Scenario 6 — WAN selection and traffic analysis (dropdown)

**Question ID:** 12464 | **Subtype:** `dropdown`

**Description**

After a power cut, the network shows performance problems and VoIP disruption. The dashboard
shows WAN1/WAN2 metrics, device status and a traffic table.

**Dashboard data**

- WAN1: 100 Mbps, 24 ms latency, **9.5 ms jitter**
- WAN2: 50 Mbps, 18 ms latency, **2.1 ms jitter**
- Router A (206.10.1.1): **FAULT** — offline since the power cut
- Router B (206.10.1.2): OK
- Workstation 10.1.90.53: **4,820 kb/s**, the highest traffic

**Questions and answers**

| Question | Correct answer | Explanation |
|----------|----------------|-------------|
| Which WAN link for VoIP? | **WAN2 (50 Mbps, 18 ms, 2.1 ms jitter)** | VoIP needs low jitter (below 30 ms, ITU-T G.114); bandwidth is secondary |
| Which router has a problem? | **Router A (206.10.1.1)** | Its status is FAULT; 206.x are the WAN router addresses |
| Which workstation generates the most traffic? | **10.1.90.53 (4,820 kb/s)** | The highest figure in the 10.1.x.x range; the 206.x hosts are routers, not workstations |

---

## Configuration format reference

### CLI format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "domain": "cisco_ios",
  "hint": "Available commands: show vlan brief | conf t | ...",
  "terminals": [
    {
      "name": "SW1",
      "welcome": "Cisco IOS Software, Version 15.2..."
    }
  ],
  "command_outputs": {
    "SW1": {
      "show vlan brief": "VLAN Name ...",
      "conf t": "",
      "interface gi0/1": "",
      "switchport access vlan 10": "% VLAN 10 assigned..."
    }
  },
  "evaluation": [
    {
      "terminal": "SW1",
      "required_pattern": "switchport access vlan 90",
      "points": 3,
      "explanation": "The port must be assigned to VLAN 90"
    }
  ]
}
```

**Notes for CLI**

- `domain` must be one of `cisco_ios`, `linux`, `windows`, `sql`, `generic`
- `command_outputs` keys are matched case-insensitively
- An empty string `""` as the output means "command accepted, no output" — `conf t`, for example
- Mode transitions happen automatically: `conf t` enters config mode, `interface x` enters
  config-if mode

### Placement format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "positions": [
    {
      "id": "pos_fw",
      "label": "Internet gateway",
      "x_pct": 50,
      "y_pct": 36,
      "correct": "Firewall"
    }
  ],
  "device_options": ["Firewall", "Router", "WAP", "Wireless Range Extender", "Switch"],
  "scoring_mode": "partial"
}
```

**Fields**

- `x_pct` / `y_pct`: position as a percentage of the SVG width and height, for the hotspot overlay
- `correct`: must match one of the values in `device_options` exactly
- `scoring_mode`: `"strict"` (all or nothing) or `"partial"` (points per correct placement)

### Dropdown format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "questions": [
    {
      "id": "q_voip",
      "label": "Which WAN interface for VoIP?",
      "options": ["WAN1 (100Mbps, 9.5ms jitter)", "WAN2 (50Mbps, 2.1ms jitter)"],
      "correct": "WAN2 (50Mbps, 2.1ms jitter)",
      "explanation": "VoIP needs low jitter. WAN2 has 2.1 ms against 9.5 ms."
    }
  ]
}
```

**Note:** `correct` must match one of the `options` values exactly, case-sensitively.

### Ranking format (since 2026-05-22)

A drag-sort: the learner drags items into the correct order. Suits order of volatility, incident
response phases, the kill chain, vulnerability prioritisation.

```json
{
  "intro_note": "Drag the items into the correct order.",
  "instructions": ["Optional top-level hints"],
  "items": [
    { "id": "i1", "label": "CPU registers and L1/L2 cache",  "correct_position": 1 },
    { "id": "i2", "label": "Active TCP/UDP connections",     "correct_position": 2 },
    { "id": "i3", "label": "Process memory, loaded DLLs",    "correct_position": 3 }
  ]
}
```

**Notes**

- `correct_position` is 1-based.
- Shuffle the authored item order before inserting, otherwise the learner sees the solution as
  the initial state. Permute with `random.shuffle(items)` before the `INSERT`.
- The learner's answer comes back as `{itemId: positionInt}`; scoring matches per item.
- The component offers a shuffle button and marks each position ✓ or ✗ after submission.

### Multi-panel format

A split screen combining `cli` with either `dropdown` or `placement`. The configuration nests:

```json
{
  "instructions": ["Run commands on the left, answer the questions on the right."],
  "cli": { /* a full cli config, including evaluation[] */ },
  "dropdown": { /* a full dropdown config */ },
  "placement": { /* a full placement config — optional, mutually exclusive with dropdown */ }
}
```

The learner's answer: `{cli: {term: [history]}, dropdown: {qid: value}, placement: {posid: device}}`.

---

## Adding a new simulation

### Step by step

**1. Choose the subtype**

- A configuration task driven by commands → `cli`
- Arranging devices in a topology → `placement`
- Identification or analysis questions → `dropdown`
- Diagnosing a cabling fault → `cable`

**2. Create the SVG diagram**

```python
import base64
svg = """<svg xmlns="http://www.w3.org/2000/svg" width="720" height="400">
  <!-- topology diagram -->
</svg>"""
b64 = "data:image/svg+xml;base64," + base64.b64encode(svg.encode()).decode()
```

**3. Build the configuration JSON** — see the format for your subtype above.

**4. Write the SQL**

```sql
INSERT INTO oc_learning_questions (
  pool_id, question_type, text, explanation, pbq_subtype, pbq_config, lang, points
) VALUES (
  81, 'pbq',
  'Scenario description...',
  'Solution: ...',
  'cli',
  '{"domain":"cisco_ios","terminals":[...]}'::jsonb,
  'en',
  5
);
```

**5. Apply it** against your Nextcloud database — for a Docker deployment, for example:

```bash
docker exec -i <db-container> psql -U <db-user> -d <db-name> < update.sql
```

**6. Verify**

```sql
SELECT id, pbq_subtype, LEFT(text, 60)
FROM oc_learning_questions
WHERE pool_id = 81 AND question_type = 'pbq'
ORDER BY id;
```

> Prefer `occ learning:import-pool-json` where you can — it validates the payload and does not
> require direct database access. Raw SQL is the fallback for PBQ configurations the importer
> does not cover yet.

---

## Pool 81 contents

```
-- ID     | Subtype    | Scenario
-- 12460  | cli        | S1: switch VLAN/LACP (SW1+SW2)
-- 12461  | cli        | S3: router A/B/C routing diagnosis
-- 12464  | dropdown   | S6: WAN selection / traffic analysis
-- 12465  | cli        | S2: MAC table / routing diagnosis
-- 12468  | placement  | S4: network topology design
-- 12472  | dropdown   | S5: APIPA / DHCP failure
```

---

## Quality notes

- **CLI output:** real Cisco IOS formatting — indentation, column widths, the codes line
- **Addresses:** taken verbatim from the source material (the router A/B/C networks)
- **SVG diagrams:** 720 px wide; a dark theme for CLI scenarios, a light theme for placement
- **Explanations:** shown after submission, giving the full solution with its reasoning
- **Exam fidelity:** scenarios 1–6 are based on documented, real N10-009 PBQ topics

---

## Addendum, 2026-03-20

- This file describes the state before the PDF audit and is only partly current.
- The canonical PBQ content for the six imported Network+ simulations lives in
  `pbq-import-n10009.json` at the repository root.
- The audit against the reference PDF is documented in
  `app/docs/PBQ-PDF-AUDIT-2026-03-20.md`.
- To synchronise the six existing PBQ questions with the database, use
  `scripts/pbq/generate_network_plus_pbq_sync_sql.py`.
