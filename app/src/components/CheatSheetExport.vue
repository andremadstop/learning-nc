<template>
  <div v-if="hasSpots || loading" class="cheat-sheet-export">
    <!-- Inline trigger (for dashboard integration) -->
    <div v-if="!showPreview" class="cheat-sheet-trigger">
      <div class="trigger-info">
        <p class="section-label">{{ t('learning', 'Spickzettel') }}</p>
        <p v-if="spotCount > 0" class="trigger-summary">
          {{ t('learning', '{count} Schwachstellen aus {courses} Kursen', { count: spotCount, courses: courseCount }) }}
        </p>
      </div>
      <NcButton
        type="secondary"
        :disabled="loading"
        @click="openPreview">
        {{ loading ? t('learning', 'Lade...') : t('learning', 'Spickzettel erstellen') }}
      </NcButton>
    </div>

    <!-- Full preview panel -->
    <div v-if="showPreview" class="cheat-sheet-preview">
      <div class="preview-header">
        <div>
          <p class="section-label">{{ t('learning', 'Spickzettel') }}</p>
          <h3>{{ t('learning', 'Deine Schwachstellen') }}</h3>
          <p class="preview-meta">
            {{ t('learning', '{count} Fragen aus {courses} Kursen', { count: spotCount, courses: courseCount }) }}
            <span v-if="totalFound > spotCount" class="preview-more">
              ({{ t('learning', '{total} insgesamt', { total: totalFound }) }})
            </span>
          </p>
        </div>
        <div class="preview-actions">
          <NcButton type="tertiary" @click="exportMarkdown">
            {{ t('learning', 'Als Markdown') }}
          </NcButton>
          <NcButton type="secondary" @click="printCheatSheet">
            {{ t('learning', 'Drucken') }}
          </NcButton>
          <NcButton type="tertiary" @click="showPreview = false">
            {{ t('learning', 'Schliessen') }}
          </NcButton>
        </div>
      </div>

      <div v-if="loading" class="loading-container">
        <NcLoadingIcon :size="32" />
      </div>

      <div v-else-if="error" class="cheat-sheet-error">
        <NcNoteCard type="error">{{ error }}</NcNoteCard>
      </div>

      <div v-else ref="printArea" class="cheat-sheet-content">
        <div v-for="(items, chapter) in groupedSpots" :key="chapter" class="chapter-group">
          <h4 class="chapter-title">{{ chapter }}</h4>
          <div v-for="spot in items" :key="spot.question_id" class="spot-card">
            <div class="spot-header">
              <span class="spot-accuracy" :class="accuracyClass(spot.accuracy)">
                {{ spot.accuracy }}%
              </span>
              <span class="spot-pool">{{ spot.pool_name }}</span>
              <span v-if="spot.course_name" class="spot-course">{{ spot.course_name }}</span>
            </div>
            <p class="spot-question">{{ spot.question_text }}</p>
            <div v-if="spot.correct_answers && spot.correct_answers.length > 0" class="spot-answer">
              <strong>{{ t('learning', 'Korrekte Antwort:') }}</strong>
              {{ spot.correct_answers.join('; ') }}
            </div>
            <p v-if="spot.explanation" class="spot-explanation">{{ spot.explanation }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { exportCheatSheetMarkdown } from '../utils/summary-export.js'

export default {
  name: 'CheatSheetExport',

  components: {
    NcButton,
    NcLoadingIcon,
    NcNoteCard,
  },

  props: {
    userName: {
      type: String,
      default: '',
    },
    limit: {
      type: Number,
      default: 50,
    },
  },

  data() {
    return {
      loading: false,
      error: '',
      cheatSheet: null,
      showPreview: false,
    }
  },

  computed: {
    spots() {
      return this.cheatSheet?.spots || []
    },
    spotCount() {
      return this.spots.length
    },
    courseCount() {
      return this.cheatSheet?.course_count || 0
    },
    totalFound() {
      return this.cheatSheet?.total_found || 0
    },
    hasSpots() {
      return this.spotCount > 0
    },
    groupedSpots() {
      const groups = {}
      for (const spot of this.spots) {
        const chapter = spot.chapter_title || spot.chapter_key || t('learning', 'Ohne Kapitel')
        if (!groups[chapter]) {
          groups[chapter] = []
        }
        groups[chapter].push(spot)
      }
      return groups
    },
  },

  mounted() {
    this.loadCheatSheet()
  },

  methods: {
    async loadCheatSheet() {
      this.loading = true
      this.error = ''
      try {
        const res = await axios.get(generateUrl('/apps/learning/api/profile/cheat-sheet'), {
          params: { limit: this.limit },
        })
        this.cheatSheet = res.data
      } catch (e) {
        this.error = e?.response?.data?.error || t('learning', 'Spickzettel konnte nicht geladen werden.')
      } finally {
        this.loading = false
      }
    },

    openPreview() {
      this.showPreview = true
      if (!this.cheatSheet) {
        this.loadCheatSheet()
      }
    },

    exportMarkdown() {
      if (!this.cheatSheet) return
      exportCheatSheetMarkdown(this.cheatSheet, this.userName)
    },

    printCheatSheet() {
      window.print()
    },

    accuracyClass(accuracy) {
      if (accuracy < 20) return 'accuracy-critical'
      if (accuracy < 35) return 'accuracy-low'
      return 'accuracy-medium'
    },
  },
}
</script>

<style scoped>
.cheat-sheet-export {
  width: 100%;
}

.section-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--color-text-maxcontrast);
  font-weight: 700;
  margin: 0 0 8px;
}

/* Trigger (compact mode) */
.cheat-sheet-trigger {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-left: 3px solid var(--color-warning);
  border-radius: var(--border-radius-large, 12px);
}

.trigger-summary {
  margin: 0;
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
}

/* Preview panel */
.cheat-sheet-preview {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-left: 3px solid var(--color-warning);
  border-radius: var(--border-radius-large, 12px);
  padding: 24px;
}

.preview-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 24px;
}

.preview-header h3 {
  margin: 0;
  font-size: 1.3em;
}

.preview-meta {
  margin: 4px 0 0;
  color: var(--color-text-maxcontrast);
}

.preview-more {
  font-style: italic;
}

.preview-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.loading-container {
  display: flex;
  justify-content: center;
  padding: 32px;
}

/* Content */
.cheat-sheet-content {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.chapter-group {
  break-inside: avoid;
}

.chapter-title {
  margin: 0 0 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--color-primary-element);
  font-size: 1.1em;
  color: var(--color-main-text);
}

.spot-card {
  padding: 16px;
  margin-bottom: 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 12px);
  background: var(--color-background-hover);
  break-inside: avoid;
}

.spot-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.spot-accuracy {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.85em;
}

.accuracy-critical {
  background: color-mix(in srgb, var(--color-error) 15%, transparent);
  color: var(--color-error);
}

.accuracy-low {
  background: color-mix(in srgb, var(--color-warning) 15%, transparent);
  color: var(--color-warning);
}

.accuracy-medium {
  background: color-mix(in srgb, var(--color-warning) 10%, transparent);
  color: var(--color-text-maxcontrast);
}

.spot-pool,
.spot-course {
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
}

.spot-question {
  margin: 0 0 8px;
  font-weight: 600;
  color: var(--color-main-text);
  line-height: 1.5;
}

.spot-answer {
  margin-bottom: 6px;
  color: var(--color-success);
  line-height: 1.5;
}

.spot-explanation {
  margin: 0;
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
  line-height: 1.5;
  white-space: pre-wrap;
}

/* Responsive */
@media (max-width: 700px) {
  .preview-header {
    flex-direction: column;
  }
  .cheat-sheet-trigger {
    flex-direction: column;
    align-items: flex-start;
  }
}

/* Print Stylesheet */
@media print {
  /* Hide everything except the cheat sheet content */
  body * {
    visibility: hidden;
  }

  .cheat-sheet-content,
  .cheat-sheet-content * {
    visibility: visible;
  }

  .cheat-sheet-content {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
  }

  /* Hide navigation and actions when printing */
  .preview-header,
  .cheat-sheet-trigger,
  .preview-actions {
    display: none !important;
  }

  /* Clean print layout */
  .cheat-sheet-preview {
    border: none;
    padding: 0;
    background: white;
  }

  .spot-card {
    border: 1px solid #ccc;
    background: white;
    page-break-inside: avoid;
  }

  .chapter-group {
    page-break-inside: avoid;
  }

  .chapter-title {
    border-bottom-color: #333;
    color: #000;
  }

  .spot-question {
    color: #000;
  }

  .spot-answer {
    color: #006600;
  }

  .spot-explanation {
    color: #444;
  }

  .spot-accuracy {
    border: 1px solid #999;
    background: transparent;
    color: #000;
  }

  .spot-pool,
  .spot-course {
    color: #666;
  }
}
</style>
