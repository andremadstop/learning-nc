<template>
  <section v-if="visible"
           class="qmedia"
           :class="{ 'qmedia--still': still }"
           :aria-label="sectionLabel"
           :aria-busy="pendingIntro ? 'true' : null"
           @keydown="onKeydown">
    <div class="qmedia__bar">
      <h3 class="qmedia__title">
        {{ phaseTitle }}
      </h3>
      <!-- #66 (Andre 18.11.): per user on the server. Native checkboxes with role="switch": a
           label to click, Space to toggle, state read out by every screen reader. -->
      <div class="qmedia__switches">
        <label class="qmedia__switch">
          <input type="checkbox" role="switch" :checked="autoplay" @change="setAutoplay($event.target.checked)">
          <span>{{ t('learning', 'Automatisch abspielen') }}</span>
        </label>
        <label class="qmedia__switch">
          <input type="checkbox" role="switch" :checked="soundsEnabled" @change="setSounds($event.target.checked)">
          <span>{{ t('learning', 'Töne') }}</span>
        </label>
      </div>
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

    <!-- Fresh-eyes C4: the intro never carries cues, so its 190 px stage was a large empty grey
         box before the first "play". The slide stage is compact and shows a cover until then. -->
    <!-- #66 (Andre 18.11., "Mini-Slideshow"): the intro and the spoken explanation of the chosen
         option are shown as slides, one per line, in time with the sound, with a row of dots for
         the progress. Their height is reserved from the start, so nothing below moves on "play".
         The intro has no keywords: the cues explain the answer and come only after it. -->
    <div class="qmedia__stage"
         :class="{ 'qmedia__stage--intro': slides }"
         :lang="lang || null">
      <template v-if="slides">
        <transition :name="still ? '' : 'qmedia-slide'">
          <p v-if="stage.line" :key="'slide-' + track?.id + '-' + stage.lineIndex" class="qmedia__slide">
            <span v-if="stage.line.role" class="qmedia__speaker">
              <span class="qmedia__dot" :style="{ background: roleColour(stage.line.role) }" aria-hidden="true">{{ roleInitial(stage.line.role) }}</span>
              <span class="qmedia__who">{{ roleName(stage.line.role) }}:</span>
            </span>
            <span class="qmedia__line">{{ stage.line.text }}</span>
          </p>
          <p v-else-if="coverText" key="cover" class="qmedia__cover" :lang="uiLang">{{ coverText }}</p>
        </transition>
        <ol v-if="timeline.length > 1" class="qmedia__progress" aria-hidden="true">
          <li v-for="(line, index) in timeline"
              :key="'dot-' + index"
              :class="{ 'qmedia__progress--done': index < stage.lineIndex || ended, 'qmedia__progress--now': index === stage.lineIndex && !ended }" />
        </ol>
      </template>
      <template v-else>
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
            <span aria-hidden="true">✗ </span><span class="qmedia__sr" :lang="uiLang">{{ t('learning', 'Häufiger Denkfehler:') }} </span>{{ cues.wrong }}
          </span>
          <span v-if="stage.right" class="qmedia__right">
            <span aria-hidden="true">✓ </span><span class="qmedia__sr" :lang="uiLang">{{ t('learning', 'Richtig:') }} </span>{{ cues.right }}
          </span>
        </div>
        <div v-if="stage.merksatz" class="qmedia__merksatz">
          <p>
            <span v-if="cues.icon" aria-hidden="true">{{ cues.icon }} </span>
            <span class="qmedia__sr" :lang="uiLang">{{ t('learning', 'Merksatz:') }} </span>{{ cues.merksatz }}
          </p>
        </div>
        <p v-if="stage.line" class="qmedia__caption">
          <span v-if="stage.line.role" class="qmedia__speaker">
            <span class="qmedia__dot" :style="{ background: roleColour(stage.line.role) }" aria-hidden="true">{{ roleInitial(stage.line.role) }}</span>
            <span class="qmedia__who">{{ roleName(stage.line.role) }}:</span>
          </span>
          <span class="qmedia__line">{{ stage.line.text }}</span>
        </p>
      </template>
    </div>

    <!-- #66: also for an option voice without a timeline (its sidecar is optional), so play and
         "Nächste Zeile" lead on to the dialog; reserved but invisible while the media of a
         question with an expected intro are loading, so nothing moves when they arrive. -->
    <div v-if="steppable || pendingIntro" class="qmedia__controls" :class="{ 'qmedia__reserved': pendingIntro }">
      <button ref="play"
              type="button"
              class="qmedia__button qmedia__button--primary"
              :disabled="!track || audioFailed"
              :aria-label="playing ? t('learning', 'Pause') : t('learning', 'Abspielen')"
              @click="togglePlay">
        <span aria-hidden="true">{{ playing ? '❚❚' : '▶' }}</span>
        <span class="qmedia__button-label">{{ playing ? t('learning', 'Pause') : t('learning', 'Abspielen') }}</span>
      </button>
      <button ref="prev" type="button" class="qmedia__button" :disabled="cursorIndex <= 0 && !ended" @click="step(-1)">
        <span aria-hidden="true">{{ isRtl ? '→' : '←' }}</span> {{ t('learning', 'Vorherige Zeile') }}
      </button>
      <button ref="next" type="button" class="qmedia__button" :disabled="ended || !steppable" @click="step(1)">
        {{ t('learning', 'Nächste Zeile') }} <span aria-hidden="true">{{ isRtl ? '←' : '→' }}</span>
      </button>
      <button ref="restart" type="button" class="qmedia__button" :disabled="cursorIndex < 0 && !ended && !playing" @click="restart">
        {{ t('learning', 'Von vorn') }}
      </button>
      <!-- Announced only while stepping without sound: the line counter, the line itself and what
           it brings onto the stage (the misconception, the right answer, at the end the key
           takeaway), the content in its own language. During playback it stays silent, so it
           never talks over the audio, and the running clock is never announced.
           The live region is always in the DOM: one inserted together with its text is not read. -->
      <span class="qmedia__status qmedia__status--lines" :class="{ 'qmedia__status--idle': !lineStatus }" aria-live="polite"><template v-if="lineStatus">{{ lineStatus }}<template v-for="(part, index) in lineAnnouncement" :key="'say-' + index">{{ ' – ' }}<span v-if="part.label" :lang="uiLang">{{ part.label + ' ' }}</span><span :lang="lang || null">{{ part.text }}</span></template></template></span>
      <span v-if="!lineStatus && clockStatus" class="qmedia__status">{{ clockStatus }}</span>
    </div>
    <p class="qmedia__note" :class="{ 'qmedia__note--idle': !audioFailed }" role="status">
      <template v-if="audioFailed">
        {{ t('learning', 'Der Ton lässt sich gerade nicht abspielen. Lies den Text mit oder geh ihn mit „Nächste Zeile“ durch.') }}
      </template>
    </p>

    <details v-if="timeline.length || pendingIntro" class="qmedia__transcript" :class="{ 'qmedia__reserved': pendingIntro }">
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
import { soundsOn, saveQuestionMediaPrefs, useOptionalQuestionMediaPrefsStore } from '../stores/questionMediaPrefsStore.js';
import { useOptionalA11yStore } from '../stores/a11yStore.js';
import { ANSWER_SOUND_MS, playAnswerSound } from '../utils/answerSounds.js';
import {
  tracksOfKind, trackLanguages, optionSegments, pickLanguage, trackFor, timelineOf, cuesFor, stageAt, lastStartedIndex,
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
 *
 * The question as a mini slideshow (Andre 18.11.):
 * - The intro and the spoken explanation of the chosen option play as slides, one per line.
 * - `conceal` (event): true while a question with a spoken intro should keep its text and answers
 *   hidden (QuestionConceal.vue) — until the intro ends. Released for good, for this question, by
 *   the end, a stepped-through intro, an audio error or a refused autoplay.
 * - After answering, the segments play in order: the voice of each chosen option that has one
 *   (`question_media.option_tracks`), then the dialog. One "play" runs through all of them.
 * - "Automatisch abspielen" (per user, off by default) starts the intro when the question is
 *   shown and the segments right after answering. A refused play() (Safari/iOS) falls back
 *   silently to the play button, and the question shows.
 * - "Töne": "Pling"/"Zonk" right after a fresh answer (never for an answer restored on jumping
 *   back), synthesised (answerSounds.js), and only for a question with media, where the switch is.
 * - A question after one with an intro is held hidden in a reserved player while its media load
 *   (`pendingIntro`), so it never shows first and then slides away; questions in pools without
 *   media are never held.
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
    // #66: whether the answer just given was right (null: not known, no tone)
    answerCorrect: { type: Boolean, default: null },
    // #66: the answers the learner chose, for the spoken explanation of each
    selectedAnswerIds: { type: Array, default: () => [] },
  },
  emits: ['conceal'],
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
      // #66 mini slideshow
      prefs: useOptionalQuestionMediaPrefsStore() || { autoplay: false, sounds: 'auto' },
      a11y: useOptionalA11yStore(),
      // play() refused without a gesture (autoplay policy): not a failure, the button stays
      blocked: false,
      // the intro has let the question go (ended, stepped through, failed, refused)
      introReleased: false,
      // this question was shown open in this player, so an answer to it is a fresh one
      seenOpen: false,
      // which post-answer segment is on: option voices first, then the dialog
      segmentIndex: 0,
      // play the next segment when this one ends (one "play" runs through all of them)
      chain: false,
      // the post-answer autoplay waits for the answer tone (Andre: tone first, then the voice)
      autoStartTimer: null,
      // GET /media of the current question is still open
      loadingIntro: false,
      // the last question loaded here had a spoken intro: the next one probably has one too
      // (a pool with media has them throughout), so its place is kept and it stays hidden
      // until its media are known — no question that shows and then slides away
      expectIntro: false,
    };
  },
  computed: {
    // #66: waiting for the media of a question that probably has an intro
    pendingIntro() {
      return !this.answered && this.loadingIntro && this.expectIntro;
    },
    phase() {
      if (this.answered) {
        return this.segment ? this.segment.kind : '';
      }
      return this.introTracks.length || this.pendingIntro ? 'intro' : '';
    },
    // After answering: the voices of the chosen options (answer order), then the dialog.
    segments() {
      if (!this.answered) {
        return [];
      }
      const out = optionSegments(this.reveal?.option_tracks, this.selectedAnswerIds)
        .map((tracks) => ({ kind: 'option', tracks }));
      if (this.dialogTracks.length || this.hasCues) {
        out.push({ kind: 'dialog', tracks: this.dialogTracks });
      }
      return out;
    },
    segment() {
      return this.segments[Math.min(this.segmentIndex, this.segments.length - 1)] || null;
    },
    hasNextSegment() {
      return this.answered && this.segmentIndex < this.segments.length - 1;
    },
    slides() {
      return this.phase === 'intro' || this.phase === 'option';
    },
    phaseTitle() {
      if (this.phase === 'dialog') {
        return this.t('learning', 'Nachbesprechung');
      }
      return this.phase === 'option' ? this.t('learning', 'Zu deiner Antwort') : this.t('learning', 'Einstieg');
    },
    sectionLabel() {
      if (this.phase === 'dialog') {
        return this.t('learning', 'Nachbesprechung mit Ton und Untertiteln');
      }
      return this.phase === 'option'
        ? this.t('learning', 'Erklärung zu deiner Antwort mit Ton und Untertiteln')
        : this.t('learning', 'Einstieg mit Ton und Untertiteln');
    },
    coverText() {
      if (this.timeline.length === 0) {
        // an option voice without a timeline: only its length, never "0 Zeilen"
        return this.track && this.duration > 0 ? formatSeconds(this.duration) : '';
      }
      const lines = this.n('learning', '%n Zeile', '%n Zeilen', this.timeline.length);
      return this.duration > 0 ? lines + ' · ' + formatSeconds(this.duration) : lines;
    },
    // Lines to step through, or an option voice without them: "Nächste Zeile" then leads on.
    steppable() {
      return this.timeline.length > 0 || (this.phase === 'option' && !!this.track);
    },
    autoplay() {
      return !!this.prefs.autoplay;
    },
    soundsEnabled() {
      return soundsOn(this.prefs);
    },
    still() {
      return this.reducedMotion || this.a11y?.animationsEnabled === false;
    },
    // #66: keep the question hidden while a spoken intro is to be heard first.
    concealQuestion() {
      return !this.answered
        && this.phase === 'intro'
        && (this.pendingIntro || (!!this.track && this.timeline.length > 0))
        && !this.introReleased
        && !this.ended
        && !this.audioFailed
        && !this.blocked;
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
      if (this.phase === 'intro') {
        return this.introTracks;
      }
      return this.segment ? this.segment.tracks : [];
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
      if ((this.manualIndex === null && !this.audioFailed) || this.timeline.length === 0) {
        return '';
      }
      return this.t('learning', 'Zeile {current} von {total}', {
        current: Math.max(0, this.stage.lineIndex + 1),
        total: this.timeline.length,
      });
    },
    // The interface language as a BCP 47 tag (Nextcloud codes use "_": "pt_BR").
    uiLang() {
      return String(getLanguage() || '').replace(/_/g, '-') || null;
    },
    // What stepping brings: the line (speaker: text) and, the first time they appear, the
    // misconception and the right answer; after the last line the key takeaway instead.
    lineAnnouncement() {
      if (!this.lineStatus) {
        return [];
      }
      const dialog = this.phase === 'dialog';
      if (this.ended) {
        return this.stage.merksatz ? [{ label: this.t('learning', 'Merksatz:'), text: this.cues.merksatz }] : [];
      }
      const parts = [];
      const line = this.stage.line;
      if (line && line.text) {
        const who = line.role ? roleName(line.role) + ': ' : '';
        parts.push({ label: '', text: who + line.text });
      }
      const before = stageAt(this.timeline, this.cues, { lineIndex: this.stage.lineIndex - 1 }, { dialog, ended: false });
      if (this.stage.wrong && !before.wrong) {
        parts.push({ label: this.t('learning', 'Häufiger Denkfehler:'), text: this.cues.wrong });
      }
      if (this.stage.right && !before.right) {
        parts.push({ label: this.t('learning', 'Richtig:'), text: this.cues.right });
      }
      return parts;
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
        this.cancelAutoStart();
        this.introReleased = false;
        this.segmentIndex = 0;
        this.chain = false;
        this.seenOpen = !this.answered;
        this.loadIntro();
      },
    },
    answered(now) {
      this.segmentIndex = 0;
      if (!now) {
        this.seenOpen = true;
        return;
      }
      // Only an answer given here and now: jumping back to an answered question is silent.
      const fresh = this.seenOpen;
      this.seenOpen = false;
      if (!fresh) {
        return;
      }
      // #66: the tones belong to questions with media — only there is the switch to silence
      // them; every other question (a mandatory training in the office) answers as before
      const hasMedia = this.introTracks.length > 0 || this.segments.length > 0;
      const toned = hasMedia && this.soundsEnabled && this.answerCorrect !== null && playAnswerSound(this.answerCorrect);
      if (this.autoplay) {
        // after the phase watcher's stop() and the render of the first segment, and after the
        // tone when there was one
        this.cancelAutoStart();
        if (toned) {
          this.autoStartTimer = setTimeout(() => {
            this.autoStartTimer = null;
            this.autoStart();
          }, ANSWER_SOUND_MS);
        } else {
          this.$nextTick(() => this.autoStart());
        }
      }
    },
    introTracks(tracks) {
      if (tracks.length && this.autoplay && !this.answered) {
        this.$nextTick(() => this.autoStart());
      }
    },
    concealQuestion: {
      immediate: true,
      handler(now, before) {
        if (!now && before && !this.answered && this.phase === 'intro') {
          // one way: starting the intro again does not hide the question again
          this.introReleased = true;
        }
        this.$emit('conceal', now);
      },
    },
    phase() {
      this.stop();
    },
    segmentIndex() {
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
    this.cancelAutoStart();
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
      this.loadingIntro = false;
      if (this.questionId === null || this.questionId === '' || this.questionId === undefined) {
        return;
      }
      this.loadingIntro = true;
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/questions/{id}/media', { id: this.questionId }));
        if (token !== this.requestToken) {
          return;
        }
        // in one step with the end of loading: concealQuestion never passes through false
        // while the intro is on, which would release the question for good
        this.introTracks = tracksOfKind(response?.data?.tracks, 'intro');
        this.loadingIntro = false;
        this.expectIntro = this.introTracks.length > 0;
      } catch (e) {
        // No media, no access or an exam running on the pool: the player simply stays away.
        if (token === this.requestToken) {
          this.introTracks = [];
          this.loadingIntro = false;
          this.expectIntro = false;
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
      this.blocked = false;
      this.currentTime = 0;
      this.manualIndex = null;
    },
    cancelAutoStart() {
      if (this.autoStartTimer !== null) {
        clearTimeout(this.autoStartTimer);
        this.autoStartTimer = null;
      }
    },
    /** Autoplay: start the current part if nothing plays yet and it is not already heard. */
    autoStart() {
      if (!this.autoplay || this.playing || this.started || !this.track || this.audioFailed) {
        return;
      }
      this.chain = true;
      this.togglePlay();
    },
    setAutoplay(on) {
      this.save({ autoplay: !!on });
      // switched on during an intro nobody has started: that click is the gesture to start it
      if (on && !this.answered && this.phase === 'intro' && !this.started) {
        this.autoStart();
      }
    },
    setSounds(on) {
      this.save({ sounds: on ? 'yes' : 'no' });
    },
    save(change) {
      if (typeof this.prefs.save === 'function') {
        return this.prefs.save(change);
      }
      return saveQuestionMediaPrefs(this.prefs, change);
    },
    /** On to the next post-answer part; it plays if the one before was playing through. */
    advanceSegment(play) {
      if (!this.hasNextSegment) {
        return false;
      }
      this.segmentIndex++;
      if (play) {
        // after the watchers' stop() and the render of the new track
        this.$nextTick(() => {
          this.chain = true;
          this.togglePlay();
        });
      }
      return true;
    },
    togglePlay() {
      const audio = this.audioElement();
      if (!audio || !this.track) {
        return;
      }
      if (this.playing) {
        audio.pause();
        this.chain = false;
        return;
      }
      // a press on "play" runs through the remaining parts; autoStart() sets it as well
      this.chain = true;
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
          if (e && e.name === 'NotAllowedError') {
            // #66: autoplay refused (Safari/iOS, no gesture yet): the play button as before.
            this.onPlayRefused();
          } else if (e && e.name !== 'AbortError') {
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
    /**
     * A button that disables itself while focused drops the focus to <body> (HTML focus fixup),
     * and with it the arrow keys of this section. Call right after the state change (so the
     * check runs after the re-render): if the focus was in the player and is no longer on an
     * enabled control, it goes to the first enabled one of $preferred.
     */
    keepFocus(preferred) {
      if (typeof document === 'undefined') {
        return;
      }
      const root = this.$el;
      const before = document.activeElement;
      if (!root || typeof root.contains !== 'function' || !before || !root.contains(before)) {
        return;
      }
      this.$nextTick(() => {
        const now = document.activeElement;
        if (now && now !== document.body && root.contains(now) && !now.disabled) {
          return;
        }
        for (const name of preferred) {
          const el = this.$refs[name];
          if (el && !el.disabled && typeof el.focus === 'function') {
            el.focus();
            return;
          }
        }
      });
    },
    step(delta) {
      if (!this.steppable) {
        return;
      }
      this.moveBy(delta);
      this.keepFocus(delta > 0 ? ['prev', 'restart'] : ['next', 'restart']);
    },
    moveBy(delta) {
      const audio = this.audioElement();
      if (this.playing && audio) {
        audio.pause();
      }
      // stepping takes over: the parts after this one no longer follow by themselves
      this.chain = false;
      const last = this.timeline.length - 1;
      let index = this.cursorIndex;
      if (this.ended && delta < 0) {
        this.ended = false;
        index = last;
      } else if (delta > 0 && index >= last) {
        // #66: past the option voice's last line: on to the next part (the dialog).
        if (this.advanceSegment(false)) {
          return;
        }
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
      this.manualIndex = null;
      if (!wasPlaying) {
        this.started = false;
      }
      this.seek(0);
      this.keepFocus(['play', 'next']);
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
      if (this.chain) {
        this.advanceSegment(true);
      }
    },
    onPlayRefused() {
      this.playing = false;
      this.started = false;
      this.chain = false;
      this.blocked = true;
      this.keepFocus(['play']);
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
      this.keepFocus(['next', 'prev']);
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
/* #66 slides (intro, option voice): two caption lines and the dots reserved from the start, so
   neither "play" nor a longer line moves the question below. */
.qmedia__stage--intro {
  min-height: calc(3em + 52px);
  padding: 12px 16px;
  justify-content: center;
  align-items: center;
  gap: 8px;
}
.qmedia__slide {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: center;
  gap: 6px 8px;
  font-size: 17px;
  line-height: 1.5;
  text-align: center;
}
.qmedia__cover { margin: 0; font-size: 13px; color: var(--color-text-maxcontrast); }
.qmedia__progress { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; margin: 0; padding: 0; list-style: none; }
.qmedia__progress li {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-border-dark);
  transition: background-color .3s, transform .3s;
}
.qmedia__progress li.qmedia__progress--done { background: var(--color-text-maxcontrast); }
.qmedia__progress li.qmedia__progress--now { background: var(--color-primary-element); transform: scale(1.35); }
.qmedia-slide-enter-active, .qmedia-slide-leave-active { transition: opacity .28s ease, transform .28s ease; }
.qmedia-slide-enter-from { opacity: 0; transform: translateY(10px); }
.qmedia-slide-leave-to { opacity: 0; transform: translateY(-6px); }
/* the old slide fades out on top of the new one instead of holding it back */
.qmedia-slide-leave-active { position: absolute; inset-inline: 16px; }

.qmedia__switches { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-inline-start: auto; }
.qmedia__switch { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--color-main-text); cursor: pointer; min-height: 34px; }
.qmedia__switch input { width: 16px; height: 16px; margin: 0; accent-color: var(--color-primary-element); cursor: pointer; }
.qmedia__switch input:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
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
.qmedia__keywords b.qmedia__keyword--doubt { text-decoration: underline wavy 2px var(--color-element-error, var(--color-error-text)); }
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
/* #66: holds the place of the controls and the transcript while the media load (not focusable) */
.qmedia__reserved { visibility: hidden; }
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
  .qmedia__switches { margin-inline-start: 0; width: 100%; }
  .qmedia__status { margin-inline-start: 0; width: 100%; }
}
</style>
