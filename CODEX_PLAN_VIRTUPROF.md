# VirtuProf — Virtueller Dozenten-Assistent

> Codex-Implementierungsplan v1.0 (2026-03-18)
> Priorität: Nach Sprach-Feature (GEMINI_PLAN_LANGUAGE.md)

---

## Was ist VirtuProf?

Ein kontextbewusster, regel-basierter Assistent-Charakter (kein LLM), der dem Schüler hilft die App zu verstehen. Visuelle SVG-Figur mit Sprechblase. Ähnlich der alten Windows-Büroklammer ("Clippy"), aber sinnvoll und respektvoll eingesetzt.

**Kein LLM, kein TTS, kein externer Service.** Vollständig offline, JSON-getrieben.

---

## Dateien, die erstellt werden

```
app/src/components/VirtuProf.vue            ← Root-Komponente (overlay)
app/src/components/VirtuProfAvatar.vue      ← SVG-Figur mit CSS-Animationen
app/src/components/VirtuProfBubble.vue      ← Sprechblase + Buttons
app/src/utils/virtuprof-scripts.js          ← Alle Trigger-Definitionen
app/lib/Controller/VirtuProfController.php  ← GET state, POST dismiss
```

**App.vue**: VirtuProf einbinden + Event-Bus initialisieren.
**PersonalSettings.vue**: Toggle "Virtueller Assistent an/aus".
**app/appinfo/routes.php**: 2 neue Routen.

---

## Architektur

### Event-Bus-System (Vue 2)

```js
// Trigger auslösen (von beliebiger Komponente):
this.$root.$emit('virtuprof:trigger', 'first-duel')
this.$root.$emit('virtuprof:trigger', 'badge-earned', { badgeName: 'Streak 7' })

// VirtuProf.vue empfängt:
mounted() {
  this.$root.$on('virtuprof:trigger', this.handleTrigger)
},
beforeDestroy() {
  this.$root.$off('virtuprof:trigger', this.handleTrigger)
}
```

### Script-Datenstruktur (`virtuprof-scripts.js`)

```js
export const SCRIPTS = {
  'app-first-visit': {
    condition: 'once',
    priority: 10,
    delay: 1500,
    steps: [
      {
        text: t('learning', 'Willkommen! Ich bin Prof. Lern. Darf ich dir kurz zeigen, wie Learning funktioniert?'),
        animation: 'wave',
        actions: [
          { label: t('learning', 'Ja, gerne!'), next: 'onboarding-step-1' },
          { label: t('learning', 'Nein danke'), dismiss: true }
        ]
      }
    ]
  },
  'training-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: t('learning', 'Im Trainings-Modus siehst du alle Fragen des Pools. Einfach los — kein Druck!'),
        animation: 'talk'
      }
    ]
  },
  'leitner-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: t('learning', 'Das Leitner-System sortiert Fragen nach deinem Lernfortschritt in 5 Kästen. Richtig beantwortet = höherer Kasten, falsch = zurück in Kasten 1.'),
        animation: 'talk'
      },
      {
        text: t('learning', 'Je höher der Kasten, desto seltener fragst du dich die Frage ab. So lernst du effizient!'),
        animation: 'talk'
      }
    ]
  },
  'exam-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: t('learning', 'Exam-Modus: Alle Fragen in einem Durchlauf, kein direktes Feedback. Am Ende siehst du dein Ergebnis.'),
        animation: 'talk'
      }
    ]
  },
  'duel-first-start': {
    condition: 'once',
    priority: 7,
    delay: 1000,
    steps: [
      {
        text: t('learning', 'Ein Duell! Du trittst gegen einen anderen Lernenden an.'),
        animation: 'talk'
      },
      {
        text: t('learning', 'Beide richtig? Wer schneller antwortet, bekommt 3 statt 2 Punkte. Beide falsch? Beide verlieren 1 Punkt. Schnell UND richtig gewinnt!'),
        animation: 'talk'
      }
    ]
  },
  'liga-first-visit': {
    condition: 'once',
    priority: 6,
    delay: 500,
    steps: [
      {
        text: t('learning', 'Die Liga läuft über einen Kurs. Alle spielen gegeneinander — die besten 4 kommen ins Finale!'),
        animation: 'talk'
      }
    ]
  },
  'badge-earned': {
    condition: 'always',
    priority: 8,
    delay: 500,
    steps: [
      {
        text: t('learning', 'Glückwunsch! Du hast ein neues Abzeichen freigeschaltet: {badgeName}'),
        animation: 'celebrate'
      }
    ]
  },
  'streak-milestone': {
    condition: 'always',
    priority: 8,
    delay: 500,
    steps: [
      {
        text: t('learning', '{days} Tage Streak! Weiter so — Konstanz schlägt Intensität.'),
        animation: 'celebrate'
      }
    ]
  },
  'exam-low-score': {
    condition: 'always',
    priority: 4,
    delay: 2000,
    steps: [
      {
        text: t('learning', 'Das war noch nicht ganz deins. Tipp: Arbeite zuerst die Fragen im Leitner-Fach 1 durch — das sind genau die, die dir schwerfallen.'),
        animation: 'talk'
      }
    ]
  },
  'return-after-absence': {
    condition: 'daily',
    priority: 3,
    delay: 2000,
    steps: [
      {
        text: t('learning', 'Willkommen zurück! Im Leitner-System warten noch Fragen auf dich.'),
        animation: 'wave'
      }
    ]
  }
}
```

---

## VirtuProf.vue (Root-Komponente)

```vue
<template>
  <transition name="virtuprof-enter">
    <div v-if="visible && enabled" class="virtuprof-container" :class="{ minimized: isMinimized }">
      <VirtuProfBubble
        v-if="currentStep && !isMinimized"
        :step="currentStep"
        :step-index="stepIndex"
        :total-steps="currentScript ? currentScript.steps.length : 1"
        @next="nextStep"
        @dismiss="dismiss"
        @action="handleAction"
      />
      <VirtuProfAvatar
        :animation="currentAnimation"
        :has-message="visible && !isMinimized"
        @click="toggleMinimize"
      />
    </div>
  </transition>
</template>

<script>
import VirtuProfAvatar from './VirtuProfAvatar.vue'
import VirtuProfBubble from './VirtuProfBubble.vue'
import { SCRIPTS } from '../utils/virtuprof-scripts.js'

export default {
  name: 'VirtuProf',
  components: { VirtuProfAvatar, VirtuProfBubble },

  props: {
    enabled: { type: Boolean, default: true }
  },

  data() {
    return {
      visible: false,
      isMinimized: false,
      currentScriptId: null,
      currentScript: null,
      stepIndex: 0,
      currentAnimation: 'idle',
      dismissedTriggers: [],   // geladen von Backend beim Mount
      queue: [],               // Trigger-Queue (priority-sorted)
      processing: false
    }
  },

  computed: {
    currentStep() {
      if (!this.currentScript) return null
      return this.currentScript.steps[this.stepIndex] || null
    }
  },

  async mounted() {
    await this.loadDismissedTriggers()
    this.$root.$on('virtuprof:trigger', this.enqueue)
  },

  beforeDestroy() {
    this.$root.$off('virtuprof:trigger', this.enqueue)
  },

  methods: {
    async loadDismissedTriggers() {
      try {
        const res = await this.$http.get(OC.generateUrl('/apps/learning/api/virtuprof/state'))
        this.dismissedTriggers = res.data.dismissed || []
      } catch (e) {
        // Silent fail — ohne Backend funktioniert VirtuProf im "always zeigen" Modus
      }
    },

    enqueue(triggerId, context = {}) {
      const script = SCRIPTS[triggerId]
      if (!script) return

      // Prüfen ob Trigger schon abgefeuert werden soll
      if (script.condition === 'once' && this.dismissedTriggers.includes(triggerId)) return

      this.queue.push({ id: triggerId, script, context, priority: script.priority || 0 })
      this.queue.sort((a, b) => b.priority - a.priority)

      if (!this.processing) {
        this.processNext()
      }
    },

    processNext() {
      if (this.queue.length === 0) {
        this.processing = false
        return
      }

      this.processing = true
      const { id, script, context } = this.queue.shift()

      // Text-Interpolation für context-Variablen (z.B. {badgeName})
      this.currentScript = this.interpolateScript(script, context)
      this.currentScriptId = id
      this.stepIndex = 0
      this.currentAnimation = this.currentScript.steps[0]?.animation || 'talk'

      setTimeout(() => {
        this.visible = true
        this.isMinimized = false
      }, script.delay || 0)
    },

    interpolateScript(script, context) {
      const interpolated = JSON.parse(JSON.stringify(script))
      interpolated.steps = interpolated.steps.map(step => ({
        ...step,
        text: step.text.replace(/\{(\w+)\}/g, (_, key) => context[key] || `{${key}}`)
      }))
      return interpolated
    },

    nextStep() {
      if (this.stepIndex < this.currentScript.steps.length - 1) {
        this.stepIndex++
        this.currentAnimation = this.currentScript.steps[this.stepIndex]?.animation || 'talk'
      } else {
        this.finish()
      }
    },

    async dismiss() {
      await this.markDismissed(this.currentScriptId)
      this.finish()
    },

    handleAction(action) {
      if (action.dismiss) {
        this.dismiss()
      } else if (action.next) {
        this.enqueue(action.next)
        this.finish()
      }
    },

    finish() {
      this.visible = false
      this.currentScript = null
      this.currentScriptId = null
      setTimeout(() => this.processNext(), 500)
    },

    toggleMinimize() {
      this.isMinimized = !this.isMinimized
    },

    async markDismissed(triggerId) {
      if (!this.dismissedTriggers.includes(triggerId)) {
        this.dismissedTriggers.push(triggerId)
      }
      try {
        await this.$http.post(OC.generateUrl('/apps/learning/api/virtuprof/dismiss'), { triggerId })
      } catch (e) { /* silent */ }
    }
  }
}
</script>
```

---

## VirtuProfAvatar.vue

SVG-basierter Charakter, 3 Animationszustände via CSS-Klassen.

```vue
<template>
  <div class="virtuprof-avatar" :class="[`animation-${animation}`, { 'has-message': hasMessage }]" @click="$emit('click')">
    <svg viewBox="0 0 60 80" xmlns="http://www.w3.org/2000/svg">
      <!-- Körper -->
      <rect x="15" y="40" width="30" height="30" rx="5" fill="#3b6fa0"/>
      <!-- Kopf -->
      <circle cx="30" cy="28" r="16" fill="#f5c5a3"/>
      <!-- Augen -->
      <circle cx="24" cy="26" r="2.5" fill="#333" class="eye-left"/>
      <circle cx="36" cy="26" r="2.5" fill="#333" class="eye-right"/>
      <!-- Mund (ändert sich per Klasse) -->
      <path class="mouth" d="M 24 33 Q 30 38 36 33" stroke="#333" stroke-width="1.5" fill="none"/>
      <!-- Doktorhut -->
      <rect x="14" y="13" width="32" height="4" rx="1" fill="#1a3a5c"/>
      <rect x="22" y="10" width="16" height="5" rx="2" fill="#1a3a5c"/>
      <!-- Quaste -->
      <line x1="38" y1="10" x2="42" y2="6" stroke="#f5c200" stroke-width="1.5"/>
      <circle cx="42" cy="6" r="2" fill="#f5c200"/>
    </svg>
  </div>
</template>

<style scoped>
.virtuprof-avatar {
  width: 60px;
  height: 80px;
  cursor: pointer;
  transition: transform 0.2s ease;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
}

.virtuprof-avatar:hover {
  transform: scale(1.1);
}

/* Idle: sanftes Wippen */
.animation-idle {
  animation: idle-bob 3s ease-in-out infinite;
}
@keyframes idle-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

/* Talk: schnelles Nicken */
.animation-talk {
  animation: talk-nod 0.5s ease-in-out infinite;
}
@keyframes talk-nod {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-3deg); }
  75% { transform: rotate(3deg); }
}

/* Celebrate: Springen */
.animation-celebrate {
  animation: celebrate-jump 0.6s ease-in-out infinite;
}
@keyframes celebrate-jump {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-12px) rotate(5deg); }
}

/* Wave: Winken */
.animation-wave {
  animation: wave-arm 0.8s ease-in-out infinite;
}
@keyframes wave-arm {
  0%, 100% { transform: rotate(-5deg); }
  50% { transform: rotate(5deg); }
}
</style>
```

---

## VirtuProfBubble.vue

```vue
<template>
  <div class="virtuprof-bubble">
    <div class="bubble-content">
      <p class="bubble-text">{{ step.text }}</p>

      <!-- Schritt-Indikator bei Multi-Step -->
      <div v-if="totalSteps > 1" class="step-dots">
        <span
          v-for="i in totalSteps"
          :key="i"
          class="dot"
          :class="{ active: i === stepIndex + 1 }"
        />
      </div>

      <!-- Custom Actions oder Standard-Buttons -->
      <div class="bubble-actions">
        <template v-if="step.actions">
          <NcButton
            v-for="action in step.actions"
            :key="action.label"
            type="secondary"
            size="small"
            @click="$emit('action', action)"
          >{{ action.label }}</NcButton>
        </template>
        <template v-else>
          <NcButton
            v-if="stepIndex < totalSteps - 1"
            type="primary"
            size="small"
            @click="$emit('next')"
          >{{ t('learning', 'Weiter') }}</NcButton>
          <NcButton
            v-else
            type="secondary"
            size="small"
            @click="$emit('dismiss')"
          >{{ t('learning', 'Ok, verstanden') }}</NcButton>
        </template>
      </div>
    </div>
    <!-- Pfeil zur Figur -->
    <div class="bubble-arrow" />
  </div>
</template>

<style scoped>
.virtuprof-bubble {
  position: absolute;
  bottom: 90px;
  right: 0;
  width: 260px;
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  padding: 16px;
  border: 1px solid var(--color-border);
  animation: bubble-appear 0.3s ease-out;
}

@keyframes bubble-appear {
  from { opacity: 0; transform: translateY(10px) scale(0.95); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

.bubble-arrow {
  position: absolute;
  bottom: -8px;
  right: 20px;
  width: 16px;
  height: 16px;
  background: white;
  border-right: 1px solid var(--color-border);
  border-bottom: 1px solid var(--color-border);
  transform: rotate(45deg);
}

.bubble-text {
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-main-text);
  margin: 0 0 12px 0;
}

.step-dots {
  display: flex;
  gap: 4px;
  margin-bottom: 10px;
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-border-dark);
  transition: background 0.2s;
}
.dot.active { background: var(--color-primary); }

.bubble-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
</style>
```

---

## Backend: VirtuProfController.php

```php
<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class VirtuProfController extends Controller {
    private IConfig $config;
    private string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        string $userId
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function getState(): JSONResponse {
        $dismissed = $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]');
        $enabled   = $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'true');
        return new JSONResponse([
            'dismissed' => json_decode($dismissed, true) ?? [],
            'enabled'   => $enabled === 'true',
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function dismiss(string $triggerId): JSONResponse {
        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        ) ?? [];

        if (!in_array($triggerId, $dismissed, true)) {
            $dismissed[] = $triggerId;
        }

        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_dismissed', json_encode($dismissed));
        return new JSONResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function setEnabled(bool $enabled): JSONResponse {
        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_enabled', $enabled ? 'true' : 'false');
        return new JSONResponse(['ok' => true]);
    }
}
```

---

## routes.php — Neue Einträge

```php
['name' => 'VirtuProf#getState',    'url' => '/api/virtuprof/state',        'verb' => 'GET'],
['name' => 'VirtuProf#dismiss',     'url' => '/api/virtuprof/dismiss',       'verb' => 'POST'],
['name' => 'VirtuProf#setEnabled',  'url' => '/api/virtuprof/enabled',       'verb' => 'PUT'],
```

---

## App.vue — Integration

```vue
<!-- Im Template, ganz unten vor </div> -->
<VirtuProf v-if="userId" :enabled="virtuProfEnabled" />

<!-- In components: -->
import VirtuProf from './components/VirtuProf.vue'

<!-- In data(): -->
virtuProfEnabled: true,

<!-- In mounted() nach Settings-Laden: -->
this.virtuProfEnabled = this.personalSettings.virtuProfEnabled !== false
```

---

## Trigger-Stellen im Code

| Datei | Wo | Trigger-ID |
|-------|----|------------|
| `App.vue` | `mounted()`, erster App-Load | `app-first-visit` |
| `App.vue` | Nach Settings-Load, >7d keine Aktivität | `return-after-absence` |
| `TrainingMode.vue` | `mounted()` | `training-first-start` |
| `LeitnerMode.vue` | `mounted()` | `leitner-first-start` |
| `ExamMode.vue` | `mounted()` | `exam-first-start` |
| `ExamMode.vue` | Nach Ergebnis < 60% | `exam-low-score` |
| `DuelMode.vue` | Erster Join/Create | `duel-first-start` |
| `CourseDetail.vue` | Liga-Tab aktiviert | `liga-first-visit` |
| `BadgeList.vue` | Nach Badge-Unlock | `badge-earned` |
| `App.vue` | Streak ≥ 7, 14, 30 | `streak-milestone` |

---

## PersonalSettings.vue — Toggle

```vue
<!-- Neues Feld in der Settings-Sektion: -->
<div class="settings-row">
  <label>{{ t('learning', 'Virtueller Assistent') }}</label>
  <NcCheckboxRadioSwitch
    :checked.sync="form.virtuProfEnabled"
    @update:checked="scheduleAutoSave"
  >
    {{ t('learning', 'Prof. Lern anzeigen') }}
  </NcCheckboxRadioSwitch>
</div>
```

---

## l10n-Ergänzungen (de.json)

Folgende Keys hinzufügen:
- `"Virtueller Assistent"` → `"Virtueller Assistent"`
- `"Prof. Lern anzeigen"` → `"Prof. Lern anzeigen"`
- `"Ok, verstanden"` → `"Ok, verstanden"`
- `"Ja, gerne!"` → `"Ja, gerne!"`
- `"Nein danke"` → `"Nein danke"`
- `"Weiter"` → `"Weiter"` (vermutlich schon vorhanden — prüfen)

---

## Implementierungsreihenfolge

1. **`VirtuProfController.php`** + routes.php → Backend-State
2. **`VirtuProfAvatar.vue`** → SVG + Animationen (visuell testbar)
3. **`VirtuProfBubble.vue`** → Sprechblase
4. **`virtuprof-scripts.js`** → 10 Basis-Skripte
5. **`VirtuProf.vue`** → Root-Komponente, Event-Bus
6. **`App.vue`** → VirtuProf einbinden
7. **Trigger-Stellen** → in TrainingMode, LeitnerMode, DuelMode etc.
8. **PersonalSettings.vue** → Toggle
9. **l10n** → Keys nachtragen

---

## Rahmenbedingungen (nicht verhandelbar)

- Vue **2.7** — kein Vue 3, kein Composition API
- Keine externen Assets, kein CDN
- Deploy-Reihenfolge aus AGENTS.md einhalten (scp Vue-Dateien ZUERST, dann `npm run build`)
- `t('learning', '...')` für alle UI-Strings
- NC-Komponenten: NcButton, NcCheckboxRadioSwitch
- Controller braucht `@NoAdminRequired`
- Keine Datenmutation an bestehenden Tabellen
