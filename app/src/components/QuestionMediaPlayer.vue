<template>
  <section v-if="visible"
           class="qmedia"
           :class="{ 'qmedia--still': reducedMotion }"
           :aria-label="phase === 'dialog' ? t('learning', 'Nachbesprechung mit Ton und Untertiteln') : t('learning', 'Einstieg mit Ton und Untertiteln')"
           @keydown="onKeydown">
    <div class="qmedia__bar">
      <h3 class="qmedia__title">
        {{ phase === 'dialog' ? t('learning', 'Nachbesprechung') : t('learning', 'Einstieg') }}
      </h3>
      <div v-if="languages.length > 1" class="qmedia__langs" role="group" :aria-label="t('learning', 'Sprache von Ton und Untertiteln')">
        <button v-for="code in languages"
                :key="code"
                type="button"
                class="qmedia__lang"
                :class="{ 'qmedia__lang--active': code === lang }"
                :aria-pressed="code === lang ? 'true' : 'false'"
                :lang="code"
                @click="chooseLanguage(code)">
          {{ languageLabel(code) }}
        </button>
      </div>
    </div>

    <div class="qmedia__stage" :lang="lang || null">
      <span v-if="cues.scene" class="qmedia__scene">{{ cues.scene }}</span>
      <span v-if="phase === 'dialog' && cues.icon" class="qmedia__icon" aria-hidden="true">{{ cues.icon }}</span>
      <div v-if="stage.trail.length" class="qmedia__trail" aria-hidden="true">
        <i v-for="(word, index) in stage.trail" :key="'trail-' + index">{{ word }}</i>
      </div>
      <div class="qmedia__keywords" aria-hidden="true">
        <b v-for="keyword in stage.keywords"
           :key="keyword.key"
           :class="{ 'qmedia__keyword--doubt': keyword.doubt }"
           :style="{ '--qmedia-role': roleColour(keyword.role) }">{{ keyword.text }}</b>
      </div>
      <div v-if="stage.wrong || stage.right" class="qmedia__contrast">
        <span v-if="stage.wrong" class="qmedia__wrong" :class="{ 'qmedia__wrong--struck': stage.struck }">
          <span aria-hidden="true">✗ </span><span class="qmedia__sr">{{ t('learning', 'Denkfehler:') }} </span>{{ cues.wrong }}
        </span>
        <span v-if="stage.right" class="qmedia__right">
          <span aria-hidden="true">✓ </span><span class="qmedia__sr">{{ t('learning', 'Richtig:') }} </span>{{ cues.right }}
        </span>
      </div>
      <div v-if="stage.merksatz" class="qmedia__merksatz">
        <p>
          <span v-if="cues.icon" aria-hidden="true">{{ cues.icon }} </span>
          <span class="qmedia__sr">{{ t('learning', 'Merksatz:') }} </span>{{ cues.merksatz }}
        </p>
      </div>
      <p v-if="stage.line" class="qmedia__caption">
        <span v-if="stage.line.role" class="qmedia__speaker">
          <span class="qmedia__dot" :style="{ background: roleColour(stage.line.role) }" aria-hidden="true">{{ roleInitial(stage.line.role) }}</span>
          <span class="qmedia__who">{{ roleName(stage.line.role) }}:</span>
        </span>
        <span class="qmedia__line">{{ stage.line.text }}</span>
      </p>
    </div>

    <div v-if="timeline.length" class="qmedia__controls">
      <button type="button"
              class="qmedia__button qmedia__button--primary"
              :disabled="!track || audioFailed"
              :aria-label="playing ? t('learning', 'Pause') : t('learning', 'Abspielen')"
              @click="togglePlay">
        <span aria-hidden="true">{{ playing ? '❚❚' : '▶' }}</span>
        <span class="qmedia__button-label">{{ playing ? t('learning', 'Pause') : t('learning', 'Abspielen') }}</span>
      </button>
      <button type="button" class="qmedia__button" :disabled="cursorIndex <= 0 && !ended" @click="step(-1)">
        <span aria-hidden="true">{{ isRtl ? '→' : '←' }}</span> {{ t('learning', 'Vorherige Zeile') }}
      </button>
      <button type="button" class="qmedia__button" :disabled="ended" @click="step(1)">
        {{ t('learning', 'Nächste Zeile') }} <span aria-hidden="true">{{ isRtl ? '←' : '→' }}</span>
      </button>
      <button type="button" class="qmedia__button" :disabled="cursorIndex < 0 && !ended && !playing" @click="restart">
        {{ t('learning', 'Von vorn') }}
      </button>
      <!-- Only the line counter is announced; the running clock would talk over the audio.
           The live region is always in the DOM: one inserted together with its text is not read. -->
      <span class="qmedia__status qmedia__status--lines" :class="{ 'qmedia__status--idle': !lineStatus }" aria-live="polite">{{ lineStatus }}</span>
      <span v-if="!lineStatus && clockStatus" class="qmedia__status">{{ clockStatus }}</span>
    </div>
    <p class="qmedia__note" :class="{ 'qmedia__note--idle': !audioFailed }" role="status">
      <template v-if="audioFailed">
        {{ t('learning', 'Der Ton lässt sich gerade nicht abspielen. Lies den Text mit oder geh ihn mit „Nächste Zeile“ durch.') }}
      </template>
    </p>

    <details v-if="timeline.length" class="qmedia__transcript">
      <summary>{{ t('learning', 'Text zum Mitlesen') }}</summary>
      <ol :lang="lang || null">
        <li v-for="(line, index) in timeline"
            :key="'line-' + index"
            :class="{ 'qmedia__transcript-current': index === stage.lineIndex }"
            :aria-current="index === stage.lineIndex ? 'true' : null">
          <strong v-if="line.role">{{ roleName(line.role) }}:</strong> {{ line.text }}
        </li>
      </ol>
    </details>

    <!-- No src until "play": the audio loads only when someone wants to hear it. -->
    <audio ref="audio"
           preload="none"
           @timeupdate="onTimeUpdate"
           @play="playing = true"
           @pause="playing = false"
           @ended="onEnded"
           @error="onAudioError" />
  </section>
</template>

<script>
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { getLanguage, isRTL } from '@nextcloud/l10n';
import {
  tracksOfKind, trackLanguages, pickLanguage, trackFor, timelineOf, cuesFor, stageAt, lastStartedIndex,
  roleName, roleColour, formatSeconds, languageLabel,
} from '../utils/questionMediaPlayer.js';

/**
 * GitHub #66: the audio of a question with subtitles and keywords, after the prototype of the
 * enriched CySA+ pool — hear the intro, answer, then follow the dialog that explains the answer
 * (keywords appear when they are said, "misconception → right", the key takeaway at the end).
 *
 * Phases, decided by the parent's `answered`, never by what a payload happens to contain:
 * - open question: the intro tracks of GET /api/questions/{id}/media (editors get more there —
 *   it is ignored, so an instructor in the student view sees what a learner sees);
 * - answered: the dialog tracks and cues of the answer response (`question_media`, only sent
 *   where the solution may be revealed: training, Leitner, Smart Queue — never in an exam).
 *
 * Without sound it is fully usable: every line can be stepped through and the whole text read.
 * The audio loads only on "play". No AI is involved, so it does not depend on the AI settings.
 * Renders nothing for a question without media (every pool without imported media).
 */
export default {
  name: 'QuestionMediaPlayer',
  props: {
    questionId: { type: [Number, String], default: null },
    answered: { type: Boolean, default: false },
    // `question_media` of the answer response
    reveal: { type: Object, default: null },
    // the content language the learner reads the question in
    contentLanguage: { type: String, default: '' },
  },
  data() {
    return {
      introTracks: [],
      requestToken: 0,
      chosenLanguage: '',
      playing: false,
      started: false,
      ended: false,
      audioFailed: false,
      currentTime: 0,
      // null while the audio is the clock; a line index while stepping without sound
      manualIndex: null,
      reducedMotion: false,
      isRtl: false,
      motionQuery: null,
      // id of the track whose audio is loaded: a new grant URL of the same track (the Leitner
      // rating response) must not restart it
      loadedTrackId: null,
    };
  },
  computed: {
    phase() {
      if (this.answered) {
        return this.dialogTracks.length || this.hasCues ? 'dialog' : '';
      }
      return this.introTracks.length ? 'intro' : '';
    },
    visible() {
      return this.phase !== '';
    },
    dialogTracks() {
      return tracksOfKind(this.reveal?.tracks, 'dialog');
    },
    hasCues() {
      // Not by this.lang: the phase decides the tracks, which decide the language.
      const cues = cuesFor(this.reveal?.cues, '');
      return cues.merksatz !== '' || cues.wrong !== '' || cues.right !== '';
    },
    tracks() {
      return this.phase === 'dialog' ? this.dialogTracks : (this.phase === 'intro' ? this.introTracks : []);
    },
    languages() {
      const langs = trackLanguages(this.tracks);
      if (langs.length === 0 && this.phase === 'dialog' && this.reveal?.cues && typeof this.reveal.cues === 'object') {
        return Object.keys(this.reveal.cues).filter((key) => this.reveal.cues[key] && typeof this.reveal.cues[key] === 'object' && !Array.isArray(this.reveal.cues[key]));
      }
      return langs;
    },
    lang() {
      return pickLanguage(this.languages, [this.chosenLanguage, this.contentLanguage, getLanguage()]);
    },
    track() {
      return trackFor(this.tracks, this.lang);
    },
    timeline() {
      return timelineOf(this.track);
    },
    cues() {
      // The intro never shows cues: learners do not get them before answering.
      return this.phase === 'dialog' ? cuesFor(this.reveal?.cues, this.lang) : cuesFor(null, '');
    },
    stage() {
      const dialog = this.phase === 'dialog';
      // Cues without a track: the contrast and the takeaway, all at once.
      if (dialog && this.timeline.length === 0) {
        return stageAt([], this.cues, { lineIndex: -1 }, { dialog, ended: true });
      }
      // Before the first "play" or "next line" the stage is still empty.
      let position = { lineIndex: -1 };
      if (this.manualIndex !== null) {
        position = { lineIndex: this.manualIndex };
      } else if (this.started) {
        position = { t: this.currentTime };
      }
      return stageAt(this.timeline, this.cues, position, { dialog, ended: this.ended });
    },
    duration() {
      const last = this.timeline[this.timeline.length - 1];
      if (this.track && Number.isFinite(this.track.duration_ms) && this.track.duration_ms > 0) {
        return this.track.duration_ms / 1000;
      }
      return last ? last.end : 0;
    },
    // Where stepping starts from: the stepped-to line, else the last line the audio has started
    // (also in a pause between two lines, where no line is "current"), else -1.
    cursorIndex() {
      if (this.manualIndex !== null) {
        return this.manualIndex;
      }
      return this.started ? lastStartedIndex(this.timeline, this.currentTime) : -1;
    },
    lineStatus() {
      if (this.manualIndex === null && !this.audioFailed) {
        return '';
      }
      return this.t('learning', 'Zeile {current} von {total}', {
        current: Math.max(0, this.stage.lineIndex + 1),
        total: this.timeline.length,
      });
    },
    clockStatus() {
      return this.started ? formatSeconds(this.currentTime) + ' / ' + formatSeconds(this.duration) : '';
    },
  },
  watch: {
    questionId: {
      immediate: true,
      handler() {
        this.stop();
        this.loadIntro();
      },
    },
    phase() {
      this.stop();
    },
    lang() {
      this.stop();
    },
  },
  mounted() {
    this.isRtl = typeof isRTL === 'function' ? isRTL() : false;
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      this.motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
      this.reducedMotion = !!this.motionQuery.matches;
      this.motionQuery.addEventListener?.('change', this.onMotionChange);
    }
  },
  beforeUnmount() {
    this.requestToken++;
    this.stop();
    this.motionQuery?.removeEventListener?.('change', this.onMotionChange);
  },
  methods: {
    roleName,
    roleColour,
    languageLabel,
    roleInitial(role) {
      return Array.from(roleName(role))[0] || '';
    },
    onMotionChange(event) {
      this.reducedMotion = !!event.matches;
    },
    async loadIntro() {
      const token = ++this.requestToken;
      this.introTracks = [];
      if (this.questionId === null || this.questionId === '' || this.questionId === undefined) {
        return;
      }
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/questions/{id}/media', { id: this.questionId }));
        if (token !== this.requestToken) {
          return;
        }
        this.introTracks = tracksOfKind(response?.data?.tracks, 'intro');
      } catch (e) {
        // No media, no access or an exam running on the pool: the player simply stays away.
        if (token === this.requestToken) {
          this.introTracks = [];
        }
      }
    },
    chooseLanguage(code) {
      this.chosenLanguage = code;
    },
    audioElement() {
      return this.$refs.audio || null;
    },
    stop() {
      const audio = this.audioElement();
      if (audio) {
        try {
          audio.pause();
          audio.removeAttribute('src');
          audio.load();
          this.loadedTrackId = null;
        } catch (e) {
          // a media element without support (tests, old browsers): nothing to stop
        }
      }
      this.playing = false;
      this.started = false;
      this.ended = false;
      this.audioFailed = false;
      this.currentTime = 0;
      this.manualIndex = null;
    },
    togglePlay() {
      const audio = this.audioElement();
      if (!audio || !this.track) {
        return;
      }
      if (this.playing) {
        audio.pause();
        return;
      }
      if (!audio.getAttribute('src') || this.loadedTrackId !== this.track.id) {
        audio.setAttribute('src', this.track.url);
        this.loadedTrackId = this.track.id;
      }
      // Resume where the learner stepped to, or from the start after the end.
      if (this.manualIndex !== null && this.timeline[this.manualIndex]) {
        this.seek(this.timeline[this.manualIndex].start);
      } else if (this.ended) {
        this.seek(0);
      }
      this.manualIndex = null;
      this.ended = false;
      this.started = true;
      let result;
      try {
        result = audio.play();
      } catch (e) {
        this.onAudioError();
        return;
      }
      if (result && typeof result.catch === 'function') {
        result.catch((e) => {
          // AbortError: a newer load/pause interrupted the play request — not a failure.
          if (e && e.name !== 'AbortError') {
            this.onAudioError();
          }
        });
      }
    },
    seek(seconds) {
      const audio = this.audioElement();
      this.currentTime = seconds;
      if (audio) {
        try {
          audio.currentTime = seconds;
        } catch (e) {
          // not seekable yet: it starts from the beginning
        }
      }
    },
    step(delta) {
      const audio = this.audioElement();
      if (this.playing && audio) {
        audio.pause();
      }
      const last = this.timeline.length - 1;
      let index = this.cursorIndex;
      if (this.ended && delta < 0) {
        this.ended = false;
        index = last;
      } else if (delta > 0 && index >= last) {
        // Past the last line: the end, with the key takeaway.
        this.manualIndex = last;
        this.ended = true;
        return;
      } else {
        index = Math.min(Math.max(index + delta, 0), last);
      }
      this.manualIndex = index;
    },
    restart() {
      const wasPlaying = this.playing;
      this.ended = false;
      if (wasPlaying) {
        this.manualIndex = null;
        this.seek(0);
        return;
      }
      this.manualIndex = null;
      this.started = false;
      this.seek(0);
    },
    onTimeUpdate() {
      const audio = this.audioElement();
      if (audio && this.manualIndex === null) {
        this.currentTime = audio.currentTime || 0;
      }
    },
    onEnded() {
      this.playing = false;
      this.ended = true;
    },
    onAudioError() {
      // An expired grant (403), a file the server cannot read (503), a format the browser does
      // not play: the text stays, stepping takes over.
      if (!this.audioElement()?.getAttribute('src')) {
        return;
      }
      this.playing = false;
      this.audioFailed = true;
      if (this.manualIndex === null) {
        this.manualIndex = Math.max(0, this.cursorIndex);
      }
    },
    onKeydown(event) {
      const tag = event.target?.tagName;
      if (tag && ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) {
        return;
      }
      if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        const forward = (event.key === 'ArrowRight') !== this.isRtl;
        event.preventDefault();
        this.step(forward ? 1 : -1);
      }
    },
  },
};
</script>

<style scoped>
.qmedia {
  margin: 0 0 16px;
  text-align: start;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large);
  background: var(--color-main-background);
  padding: 10px 12px;
}
.qmedia__bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.qmedia__title { margin: 0; font-size: 15px; font-weight: 700; }
.qmedia__langs { display: flex; gap: 4px; flex-wrap: wrap; }
.qmedia__lang, .qmedia__button {
  font: inherit;
  min-height: 34px;
  padding: 4px 12px;
  border: 1px solid var(--color-border-dark);
  border-radius: var(--border-radius-element, 8px);
  background: var(--color-background-hover);
  color: var(--color-main-text);
  cursor: pointer;
}
.qmedia__lang--active, .qmedia__button--primary {
  background: var(--color-primary-element);
  border-color: var(--color-primary-element);
  color: var(--color-primary-element-text);
}
/* Nextcloud core gives every plain button a light primary background on :hover/:focus at (0,2,1),
   which beat the rule above: a focused or hovered Play showed white on light blue (1.3:1). */
.qmedia .qmedia__lang--active:hover, .qmedia .qmedia__lang--active:focus,
.qmedia .qmedia__button--primary:hover, .qmedia .qmedia__button--primary:focus {
  background: var(--color-primary-element-hover);
  border-color: var(--color-primary-element-hover);
  color: var(--color-primary-element-text);
}
.qmedia__lang:focus-visible, .qmedia__button:focus-visible, .qmedia__transcript summary:focus-visible {
  outline: 2px solid var(--color-primary-element);
  outline-offset: 2px;
}
.qmedia__button:disabled { opacity: .5; cursor: default; }

.qmedia__stage {
  position: relative;
  min-height: 190px;
  padding: 36px 16px 12px;
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.qmedia__scene {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-start: 14px;
  font-size: 12px;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--color-text-maxcontrast);
}
.qmedia__icon { position: absolute; inset-block-start: 6px; inset-inline-end: 14px; font-size: 28px; }
.qmedia__trail { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
.qmedia__trail i {
  font-style: normal;
  font-size: 13px;
  padding: 2px 10px;
  border: 1px solid var(--color-border-dark);
  border-radius: 999px;
  background: var(--color-main-background);
  color: var(--color-text-maxcontrast);
  animation: qmedia-pop .3s both;
}
.qmedia__keywords { display: flex; flex-direction: column; align-items: center; gap: .3em; min-height: 1.2em; }
.qmedia__keywords b {
  font-size: clamp(20px, 3.6vw, 36px);
  line-height: 1.15;
  text-align: center;
  overflow-wrap: anywhere;
  color: var(--color-main-text);
  text-decoration: underline 4px var(--qmedia-role, var(--color-primary-element));
  text-underline-offset: .2em;
  animation: qmedia-pop .45s cubic-bezier(.2, 1.4, .4, 1) both;
}
.qmedia__keywords b.qmedia__keyword--doubt { text-decoration: underline wavy 2px var(--color-error); }
.qmedia__contrast { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; font-size: 16px; }
.qmedia__wrong { color: var(--color-error-text); transition: opacity .5s; }
.qmedia__wrong--struck { text-decoration: line-through; opacity: .7; }
.qmedia__right { color: var(--color-success-text); font-weight: 700; animation: qmedia-slide .5s both; }
.qmedia__merksatz { display: flex; justify-content: center; animation: qmedia-fade .6s both; }
.qmedia__merksatz p {
  margin: 0;
  max-width: 90%;
  padding: .5em .9em;
  border-radius: 14px;
  background: #ffd84d;
  color: #14181f;
  font-weight: 800;
  font-size: clamp(17px, 2.8vw, 26px);
  text-align: center;
  transform: rotate(-1.5deg);
  box-shadow: 0 6px 24px rgba(0, 0, 0, .25);
}
.qmedia__caption { margin: auto 0 0; display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 8px; font-size: 15px; }
.qmedia__speaker { display: inline-flex; align-items: center; gap: 6px; }
.qmedia__dot {
  display: inline-grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  font-weight: 700;
  font-size: 13px;
  color: #0b1118;
}
.qmedia__who { color: var(--color-text-maxcontrast); font-weight: 600; }
.qmedia__line { white-space: pre-line; overflow-wrap: anywhere; }

.qmedia__controls { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; }
.qmedia__status { margin-inline-start: auto; font-size: 13px; color: var(--color-text-maxcontrast); font-variant-numeric: tabular-nums; }
.qmedia__note { margin: 6px 0 0; font-size: 13px; color: var(--color-text-maxcontrast); }
.qmedia__note--idle { margin: 0; }
/* empty but present (see the template): out of the flex flow, so it adds no gap */
.qmedia__status--idle { position: absolute; margin: 0; }
.qmedia__transcript { margin-top: 8px; }
.qmedia__transcript summary { cursor: pointer; color: var(--color-main-text); }
.qmedia__transcript ol { margin: 6px 0 0; padding-inline-start: 22px; }
.qmedia__transcript li { margin: 4px 0; white-space: pre-line; overflow-wrap: anywhere; }
.qmedia__transcript-current { background: var(--color-primary-element-light); color: var(--color-primary-element-light-text); border-radius: 4px; }
.qmedia__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@keyframes qmedia-pop { from { opacity: 0; transform: scale(.6) translateY(12px); } to { opacity: 1; transform: none; } }
@keyframes qmedia-slide { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }
@keyframes qmedia-fade { from { opacity: 0; } to { opacity: 1; } }

.qmedia--still *, .qmedia--still *::before, .qmedia--still *::after { animation: none !important; transition: none !important; }
.qmedia--still .qmedia__merksatz p { transform: none; }
@media (prefers-reduced-motion: reduce) {
  .qmedia *, .qmedia *::before, .qmedia *::after { animation: none !important; transition: none !important; }
  .qmedia__merksatz p { transform: none; }
}
@media (max-width: 480px) {
  .qmedia__button-label { display: none; }
  .qmedia__status { margin-inline-start: 0; width: 100%; }
}
</style>
