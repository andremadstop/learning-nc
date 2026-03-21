export const SCRIPTS = {
  'app-first-visit': {
    condition: 'once',
    priority: 10,
    delay: 1400,
    steps: [
      {
        text: "Welcome! I'm Prof. Lern, your virtual study assistant.",
        animation: 'wave',
      },
      {
        text: 'I give short tips right where they help most. You can turn me off any time in Personal Settings.',
        animation: 'talk',
      },
    ],
  },
  'training-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: 'Im Training beantwortest du Fragen aus dem Pool und bekommst direkt Feedback. Du kannst oben zwischen Mehrfachauswahl und Wahr/Falsch wechseln — beide Typen können in einer Session gemischt vorkommen.',
        animation: 'talk',
      },
    ],
  },
  'leitner-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: 'The Leitner system sorts questions into 5 boxes based on your learning progress. Correct answers move up, wrong answers go back to Box 1.',
        animation: 'talk',
      },
      {
        text: 'The higher the box, the less often you review that card. That keeps practice focused and efficient.',
        animation: 'talk',
      },
    ],
  },
  'exam-first-start': {
    condition: 'once',
    priority: 5,
    delay: 800,
    steps: [
      {
        text: 'Exam mode runs like a real exam: one session, no immediate feedback, full review at the end.',
        animation: 'talk',
      },
    ],
  },
  'duel-first-start': {
    condition: 'once',
    priority: 7,
    delay: 1000,
    steps: [
      {
        text: 'Beim Duell trittst du direkt gegen einen anderen Lernenden an.',
        animation: 'talk',
      },
      {
        text: 'Richtige Antworten zählen — bei Gleichstand entscheidet die Geschwindigkeit über den höheren Bonus.',
        animation: 'talk',
      },
    ],
  },
  'arena-first-visit': {
    condition: 'once',
    priority: 7,
    delay: 800,
    steps: [
      {
        text: 'Die Arena bietet drei Modi: Duell (1 gegen 1), Sprint (2–5 Spieler, schnellste Antwort gewinnt) und Elimination (2–5 Spieler, 3 Leben).',
        animation: 'wave',
      },
    ],
  },
  'gameshow-sprint-first-start': {
    condition: 'once',
    priority: 6,
    delay: 800,
    steps: [
      {
        text: 'Im Sprint-Modus zählt Geschwindigkeit: Wer als Erste oder Erster richtig antwortet, bekommt die meisten Punkte. Nach jeder Frage siehst du die aktuelle Rangliste.',
        animation: 'talk',
      },
      {
        text: 'Eine Runde umfasst 15 Fragen. Halte dein Tempo hoch!',
        animation: 'celebrate',
      },
    ],
  },
  'gameshow-elimination-first-start': {
    condition: 'once',
    priority: 6,
    delay: 800,
    steps: [
      {
        text: 'Im Elimination-Modus startest du mit 3 Leben. Jede falsche Antwort kostet dich ein Leben.',
        animation: 'talk',
      },
      {
        text: 'Sobald nur noch 2 Spieler übrig sind, beginnt der Sudden Death — da gibt es keine Leben mehr.',
        animation: 'talk',
      },
    ],
  },
  'liga-first-visit': {
    condition: 'once',
    priority: 6,
    delay: 600,
    steps: [
      {
        text: 'League play happens inside one course. Everyone plays everyone, and the top 4 move on to the final round.',
        animation: 'talk',
      },
    ],
  },
  'badge-earned': {
    condition: 'always',
    priority: 8,
    delay: 500,
    steps: [
      {
        text: 'Congratulations! You unlocked a new badge: {badgeName}',
        animation: 'celebrate',
      },
    ],
  },
  'streak-milestone': {
    condition: 'always',
    priority: 8,
    delay: 500,
    steps: [
      {
        text: '{days} days in a row. Consistency beats intensity.',
        animation: 'celebrate',
      },
    ],
  },
  'exam-low-score': {
    condition: 'always',
    priority: 4,
    delay: 1800,
    steps: [
      {
        text: 'That one was rough. Tip: review your weakest questions in Leitner Box 1 first and then try the exam again.',
        animation: 'talk',
      },
    ],
  },
  // TRIG-01: Shown after exam <70% when a weakness note was auto-generated
  'exam-note-generated': {
    condition: 'always',
    priority: 9,
    delay: 2500,
    steps: [
      {
        text: 'Ich habe eine Zusammenfassung für dein schwächstes Thema erstellt. Du findest sie in deinem /Learning/Zusammenfassungen/-Ordner.',
        animation: 'talk',
      },
    ],
  },
  // TRIG-02: Shown after 5 wrong answers on the same topic
  'suggest-summary': {
    condition: 'always',
    priority: 8,
    delay: 800,
    steps: [
      {
        text: 'Du hast diese Fragen öfter falsch beantwortet. Möchtest du eine Zusammenfassung für dieses Thema erstellen?',
        animation: 'talk',
        actions: [
          { type: 'generate-note-for-context', label: 'Zusammenfassung erstellen' },
          { type: 'close-help', label: 'Nicht jetzt' },
        ],
      },
    ],
  },
  'return-after-absence': {
    condition: 'daily',
    priority: 3,
    delay: 1800,
    steps: [
      {
        text: 'Welcome back! There are still questions waiting for you in the Leitner system.',
        animation: 'wave',
      },
    ],
  },
  'gameshow-round-announce': {
    condition: 'always',
    priority: 6,
    delay: 300,
    steps: [
      {
        text: 'Runde {round} von {total} — los geht\'s!',
        animation: 'wave',
      },
    ],
  },
  'gameshow-standings-update': {
    condition: 'always',
    priority: 5,
    delay: 200,
    steps: [
      {
        text: '{commentary}',
        animation: 'talk',
      },
    ],
  },
  'gameshow-answer-correct': {
    condition: 'always',
    priority: 4,
    delay: 100,
    steps: [
      {
        text: '{message}',
        animation: 'celebrate',
      },
    ],
  },
  'gameshow-answer-wrong': {
    condition: 'always',
    priority: 4,
    delay: 100,
    steps: [
      {
        text: '{message}',
        animation: 'talk',
      },
    ],
  },
  'gameshow-elimination': {
    condition: 'always',
    priority: 7,
    delay: 400,
    steps: [
      {
        text: 'Auf Wiedersehen, {playerName}! Das war ein guter Kampf.',
        animation: 'talk',
      },
    ],
  },
  'gameshow-winner': {
    condition: 'always',
    priority: 8,
    delay: 500,
    steps: [
      {
        text: '{winnerName} gewinnt die Arena-Runde!',
        animation: 'celebrate',
      },
    ],
  },
  'arena-session-restored': {
    condition: 'always',
    priority: 7,
    delay: 600,
    steps: [
      {
        text: 'Deine Session wurde wiederhergestellt. Du kannst dort weitermachen, wo du aufgehört hast.',
        animation: 'wave',
      },
    ],
  },
}

export const FAQ_CATEGORIES = {
  gettingStarted: {
    label: 'Erste Schritte',
    title: 'Erste Schritte',
    description: 'Kurse, Pools, Sperren und Spracheinstellungen — das Wichtigste auf einen Blick.',
  },
  training: {
    label: 'Training',
    title: 'Training',
    description: 'Sofortiges Feedback, Fragetypen, MC/WF-Toggle und der schnellste Weg zum Üben.',
  },
  leitner: {
    label: 'Leitner & Queue',
    title: 'Leitner & Queue',
    description: 'Spaced Repetition, Boxen, fällige Karten und Problemzonen.',
  },
  exam: {
    label: 'Prüfung',
    title: 'Prüfung',
    description: 'Zeitlimit, Review-Ablauf, Tab-Sperre und Abschlussregeln.',
  },
  arena: {
    label: 'Arena',
    title: 'Arena',
    description: 'Duell, Sprint und Elimination — alle kompetitiven Modi auf einen Blick.',
  },
  league: {
    label: 'Liga',
    title: 'Liga',
    description: 'Herausforderungen, Tabelle, Saisonablauf und Champions League.',
  },
  progress: {
    label: 'Fortschritt & Belohnungen',
    title: 'Fortschritt & Belohnungen',
    description: 'Mastery, Rangliste, XP, Streaks und Badges.',
  },
  settings: {
    label: 'Einstellungen & Sprache',
    title: 'Einstellungen & Sprache',
    description: 'Inhaltssprache, Assistent-Toggle, Kalender-Sync und Präferenzen.',
  },
}

export const FAQS = {
  'start-course': {
    category: 'gettingStarted',
    label: 'Wie starte ich das Lernen in einem Kurs?',
    title: 'Wie starte ich das Lernen in einem Kurs?',
    text: 'Öffne einen Kurs, wähle einen Lernmodus wie Training oder Leitner und dann einen der zugewiesenen Pools. Der Modus startet erst nach der Pool-Auswahl — der Dozent steuert das verfügbare Material über Kurspools und optionale Filter.',
  },
  'course-vs-pool': {
    category: 'gettingStarted',
    label: 'Was ist der Unterschied zwischen Kurs und Pool?',
    title: 'Was ist der Unterschied zwischen Kurs und Pool?',
    text: 'Ein Kurs ist der Lernraum für eine Klasse oder einen Prüfungsstrang. Ein Pool ist eine Sammlung von Fragen. Kurse können mehrere Pools enthalten, und derselbe Pool kann in verschiedenen Kontexten wiederverwendet werden, ohne die Fragen neu zu schreiben.',
  },
  'pool-locked': {
    category: 'gettingStarted',
    label: 'Warum ist ein Pool gesperrt?',
    title: 'Warum ist ein Pool gesperrt?',
    text: 'Ein Pool ist gesperrt, wenn der Kurs mindestens einen erforderlichen und erzwungenen Pool enthält, den du noch nicht abgeschlossen hast. In diesem Fall bleiben optionale Pools gesperrt, bis jede gefilterte Frage im Pflichtpool mindestens einmal beantwortet wurde.',
  },
  'fewer-questions': {
    category: 'gettingStarted',
    label: 'Warum zeigt ein Pool weniger Fragen als erwartet?',
    title: 'Warum zeigt ein Pool weniger Fragen als erwartet?',
    text: 'Ein Kurs kann Filter auf einen Pool anwenden, ohne den ursprünglichen Pool zu verändern. Typische Filter sind Prüfungsschlüssel, Kapitelschlüssel oder eine feste Liste von Fragen-IDs. Daher kann ein Kurspool weniger Fragen zeigen als der Gesamtpool.',
  },
  'content-language': {
    category: 'gettingStarted',
    label: 'Warum sehe ich übersetzte Inhalte?',
    title: 'Warum sehe ich übersetzte Inhalte?',
    text: 'Wenn in den persönlichen Einstellungen eine Inhaltssprache festgelegt ist, versucht Learning, Frage- und Antworttexte in dieser Sprache anzuzeigen. Falls keine Übersetzung vorhanden ist, wird der Originaltext verwendet.',
  },
  'training-overview': {
    category: 'training',
    label: 'Wozu ist der Training-Modus?',
    title: 'Wozu ist der Training-Modus?',
    text: 'Training ist der entspannte Übungsmodus. Du beantwortest eine Frage nach der anderen und bekommst sofort Feedback. Du kannst den Pool beliebig oft wiederholen — ideal zum Einüben, Kennenlernen und ersten Durcharbeiten eines Themas.',
  },
  'training-toggle': {
    category: 'training',
    label: 'Was macht der Toggle oben im Training?',
    title: 'MC/Wahr-Falsch-Toggle',
    text: 'Im Training kannst du oben zwischen Mehrfachauswahl (MC) und Wahr/Falsch umschalten. Bei MC wählst du eine oder mehrere Antworten aus; bei Wahr/Falsch entscheidest du per Swipe oder Tipp. Beide Typen können in einer Session gemischt erscheinen, wenn du den Toggle nicht festlegst.',
  },
  'training-swipe': {
    category: 'training',
    label: 'Kann ich im Training swipen?',
    title: 'Swipe-Gesten im Training',
    text: 'Ja. Im Wahr/Falsch-Modus kannst du nach rechts (wahr) oder links (falsch) wischen. Die Swipe-Geste ist optional — Tipp auf die Schaltfläche funktioniert genauso.',
  },
  'training-feedback': {
    category: 'training',
    label: 'Welches Feedback bekomme ich im Training?',
    title: 'Welches Feedback bekomme ich im Training?',
    text: 'Nach jeder Antwort siehst du, ob sie richtig war, welche Antwort korrekt gewesen wäre, und — wenn vorhanden — eine Erklärung oder einen Dozentenhinweis. Das macht Training zum schnellsten Modus, um sofort aus Fehlern zu lernen.',
  },
  'training-question-types': {
    category: 'training',
    label: 'Welche Fragetypen gibt es im Training?',
    title: 'Welche Fragetypen gibt es im Training?',
    text: 'Training kann Einfachauswahl, Mehrfachauswahl, Freitextfragen und PBQs enthalten. Bei Mehrfachauswahl müssen alle richtigen Optionen gewählt werden, Freitextfragen werden mit einer Musterlösung verglichen und PBQs nutzen eine interaktive Aufgabenkomponente.',
  },
  'training-explain': {
    category: 'training',
    label: 'Was macht die Erklärfunktion?',
    title: 'Was macht die Erklärfunktion?',
    text: 'Wenn die Erklärfunktion in deinem Setup verfügbar ist, kann Training nach deiner Antwort eine kurze Erklärung zur aktuellen Frage anfordern. Das ist als zusätzliche Unterstützung gedacht — nicht als verbindliche Quelle über die Lösung oder Dozentenhinweise.',
  },
  'training-results': {
    category: 'training',
    label: 'Was sehe ich nach dem Training?',
    title: 'Was sehe ich nach dem Training?',
    text: 'Am Ende siehst du deine Punktzahl, die Anzahl richtiger Antworten, gewonnene XP, Streak-Effekte und mögliche Badge-Freischaltungen. Das Training vergleicht dein Ergebnis auch mit deinem Durchschnitt und kann eine persönliche Bestleistung markieren.',
  },
  'leitner-overview': {
    category: 'leitner',
    label: 'Wie funktioniert das Leitner-System?',
    title: 'Wie funktioniert das Leitner-System?',
    text: 'Leitner nutzt Spaced Repetition mit 5 Boxen. Richtige Antworten verschieben eine Karte in eine höhere Box, falsche Antworten schicken sie zurück. Höhere Boxen kommen seltener dran — so konzentriert das System deine Zeit auf schwaches Material.',
  },
  'leitner-due': {
    category: 'leitner',
    label: 'Was bedeutet fällig im Leitner-System?',
    title: 'Was bedeutet fällig?',
    text: 'Eine fällige Karte ist bereit zur Wiederholung, basierend auf ihrer aktuellen Box und dem Zeitplan. Wenn keine Karten fällig sind, zeigt das Dashboard, dass du gerade alles aufgeholt hast.',
  },
  'smart-queue': {
    category: 'leitner',
    label: 'Was ist die Smart Queue?',
    title: 'Was ist die Smart Queue?',
    text: 'Die Smart Queue kombiniert fällige Leitner-Fragen aus mehreren Pools und sortiert sie nach Priorität — dringendste Fragen zuerst. Sie ist nützlich, wenn du eine fokussierte Review-Liste möchtest, statt die Pools einzeln zu öffnen.',
  },
  'remediation': {
    category: 'leitner',
    label: 'Was sind Problemzonen?',
    title: 'Was sind Problemzonen?',
    text: 'Problemzonen (Trouble Spots) ist die Remediation-Ansicht. Sie sammelt Fragen, die dir aktuell Schwierigkeiten machen, damit du schwache Punkte direkt angehen kannst, ohne die gesamte Queue durchzulaufen.',
  },
  'leitner-pool-vs-queue': {
    category: 'leitner',
    label: 'Wann nutze ich Leitner, Smart Queue oder Training?',
    title: 'Wann nutze ich was?',
    text: 'Nutze Training für breites, entspanntes Üben innerhalb eines Pools. Nutze Leitner für strukturiertes Spaced Repetition in einem Pool. Nutze die Smart Queue, wenn du fällige Fragen aus mehreren Pools in einer priorisierten Review-Session zusammenfassen möchtest.',
  },
  'exam-overview': {
    category: 'exam',
    label: 'Was ist am Prüfungs-Modus anders?',
    title: 'Was ist am Prüfungs-Modus anders?',
    text: 'Der Prüfungs-Modus verhält sich wie ein Testlauf: Du wählst Zeitlimit und Fragenzahl, arbeitest eine kontinuierliche Session durch und bekommst während der Beantwortung kein sofortiges Feedback zur Korrektheit. Die detaillierte Auswertung kommt erst nach dem Ende.',
  },
  'exam-time-limit': {
    category: 'exam',
    label: 'Was passiert, wenn die Zeit abläuft?',
    title: 'Was passiert, wenn die Zeit abläuft?',
    text: 'Wenn das Zeitlimit abläuft, wird die Prüfung vom Server automatisch abgegeben. Du wirst dann zur Ergebnisseite und der detaillierten Auswertung weitergeleitet, statt die Fragerunde fortzusetzen.',
  },
  'exam-tab-lock': {
    category: 'exam',
    label: 'Warum ist eine Prüfung in einem anderen Tab schreibgeschützt?',
    title: 'Warum ist die Prüfung schreibgeschützt?',
    text: 'Learning schützt aktive Prüfungen davor, gleichzeitig in mehreren Tabs beantwortet zu werden. Wenn die Prüfung bereits anderswo aktiv ist, kann ein weiterer Tab den Zustand nur im Lesemodus anzeigen.',
  },
  'exam-abort-end': {
    category: 'exam',
    label: 'Unterschied zwischen Prüfung beenden und Abbrechen?',
    title: 'Beenden vs. Abbrechen',
    text: 'Prüfung beenden schließt den aktuellen Versuch ab und speichert das Ergebnis basierend auf dem bisher Beantworteten. Abbrechen verwirft den Versuch stattdessen — es wird kein reguläres Ergebnis gespeichert.',
  },
  'exam-review': {
    category: 'exam',
    label: 'Was sehe ich in der Prüfungsauswertung?',
    title: 'Was sehe ich in der Prüfungsauswertung?',
    text: 'Die Auswertung zeigt deine Punktzahl, richtige und falsche Antworten, benötigte Zeit, Fragen pro Minute und eine Frage-für-Frage-Aufschlüsselung. Je nach Fragetyp siehst du auch deine eigene Antwort, die richtige Antwort und Dozentenhinweise.',
  },
  'arena-overview': {
    category: 'arena',
    label: 'Was ist die Arena?',
    title: 'Was ist die Arena?',
    text: 'Die Arena fasst alle kompetitiven Modi zusammen: Duell (1 gegen 1), Sprint (2–5 Spieler, Geschwindigkeit entscheidet) und Elimination (2–5 Spieler, 3 Leben). Alle Modi starten über einen Einladungscode und eine gemeinsame Lobby.',
  },
  'duel-create-join': {
    category: 'arena',
    label: 'Wie starte ich ein Duell?',
    title: 'Wie starte ich ein Duell?',
    text: 'Ein Duell startest du entweder, indem du ein neues Duell erstellst und den Code teilst, oder indem du einem bestehenden Duell mit diesem Code beitrittst. Beide Spieler betreten dann die Lobby und müssen sich bereit erklären, bevor die erste Frage beginnt.',
  },
  'duel-pool-questions': {
    category: 'arena',
    label: 'Wer wählt Pool und Fragenzahl?',
    title: 'Wer wählt Pool und Fragenzahl?',
    text: 'Die Person, die das Duell erstellt, wählt den Pool und die Anzahl der Fragen. Die Gegenpartei tritt über den Duell-Code genau diesem Setup bei.',
  },
  'duel-scoring': {
    category: 'arena',
    label: 'Wie wird die Duell-Wertung berechnet?',
    title: 'Wie wird die Duell-Wertung berechnet?',
    text: 'Richtige Antworten zählen zuerst. Wenn beide Spieler eine Frage korrekt beantworten, entscheidet die Geschwindigkeit über den höheren Bonus. Nach jeder Frage aktualisiert sich die Punkteleiste — du kannst das Duell live kippen sehen.',
  },
  'duel-feedback': {
    category: 'arena',
    label: 'Warum warte ich nach meiner Antwort?',
    title: 'Warum warte ich nach meiner Antwort?',
    text: 'Nachdem du geantwortet hast, bleibt deine Auswahl sichtbar und die Frage wartet, bis die Gegenpartei diese Runde ebenfalls abgeschlossen hat. Dann erhalten beide den kurzen Feedback-Bildschirm, bevor die nächste Frage beginnt.',
  },
  'duel-rematch': {
    category: 'arena',
    label: 'Was passiert nach einem Duell?',
    title: 'Was passiert nach einem Duell?',
    text: 'Nach dem Endstand siehst du den Sieger und kannst ein Rückspiel starten. Wenn das Duell ligagebunden war, wird das Ergebnis auch in die Ligawertung übernommen.',
  },
  'arena-sprint-overview': {
    category: 'arena',
    label: 'Wie funktioniert der Sprint-Modus?',
    title: 'Wie funktioniert der Sprint-Modus?',
    text: 'Im Sprint treten 2 bis 5 Spieler gleichzeitig an. Wer als Erste oder Erster richtig antwortet, bekommt die meisten Punkte. Nach jeder der 15 Fragen wird die Live-Rangliste aktualisiert — du siehst sofort, wo du stehst.',
  },
  'arena-sprint-scoring': {
    category: 'arena',
    label: 'Wie werden Sprint-Punkte vergeben?',
    title: 'Wie werden Sprint-Punkte vergeben?',
    text: 'Punkte hängen von Korrektheit und Geschwindigkeit ab. Die schnellste richtige Antwort erhält den vollen Bonus, langsamere richtige Antworten weniger. Falsche Antworten geben keine Punkte.',
  },
  'arena-elimination-overview': {
    category: 'arena',
    label: 'Wie funktioniert Elimination?',
    title: 'Wie funktioniert Elimination?',
    text: 'Im Elimination-Modus starten alle Spieler mit 3 Leben. Jede falsche Antwort kostet ein Leben. Wer alle Leben verliert, scheidet aus und schaut dem Rest zu. Ab 2 verbleibenden Spielern beginnt der Sudden Death — dort gibt es keine Leben mehr.',
  },
  'arena-elimination-sudden-death': {
    category: 'arena',
    label: 'Was ist Sudden Death?',
    title: 'Was ist Sudden Death?',
    text: 'Sudden Death tritt ein, wenn nur noch 2 Spieler übrig sind. Ab diesem Punkt scheidet aus, wer als Erste oder Erster eine falsche Antwort gibt — Leben spielen keine Rolle mehr. Der verbleibende Spieler gewinnt die Runde.',
  },
  'arena-session-robustness': {
    category: 'arena',
    label: 'Was passiert, wenn ich die Verbindung verliere?',
    title: 'Was passiert, wenn ich die Verbindung verliere?',
    text: 'Arena-Sessions sind verbindungsrobust. Bei einem Reload oder kurzen Verbindungsabbruch wird deine Session wiederhergestellt und du kannst dort weitermachen, wo du aufgehört hast. Ein Abbrechen-Button ist jederzeit sichtbar, falls du die Session vorzeitig beenden möchtest.',
  },
  'league-overview': {
    category: 'league',
    label: 'Wie funktioniert die Liga?',
    title: 'Wie funktioniert die Liga?',
    text: 'Die Liga findet innerhalb eines Kurses statt. Lernende fordern sich gegenseitig heraus, spielen verknüpfte Duelle und sammeln Punkte in einer Tabelle. Die Saison hat außerdem einen separaten Champions-League-Pool für die Endrunde.',
  },
  'league-standings': {
    category: 'league',
    label: 'Wie wird die Ligatabelle berechnet?',
    title: 'Wie wird die Ligatabelle berechnet?',
    text: 'Die Tabelle zählt gespielte Partien, Siege, Niederlagen, Punkte und Punktedifferenz. Punkte entscheiden zuerst über die Platzierung, die Punktedifferenz dient als Tiebreaker.',
  },
  'league-challenge-flow': {
    category: 'league',
    label: 'Wie laufen Liga-Herausforderungen ab?',
    title: 'Wie laufen Liga-Herausforderungen ab?',
    text: 'Du forderst eine Person aus der Tabelle heraus, diese akzeptiert die Herausforderung, und das System erstellt das verknüpfte Duell. Eingehende und ausgehende Herausforderungen werden getrennt angezeigt, damit du siehst, was noch Aktion erfordert.',
  },
  'league-open-duel': {
    category: 'league',
    label: 'Wann erscheint Duell öffnen im Liga-Spiel?',
    title: 'Wann erscheint Duell öffnen?',
    text: 'Duell öffnen erscheint, sobald eine Herausforderung angenommen wurde und ein Duell-Code bereits existiert. Ab diesem Punkt ist die Partie bereit und kann direkt aus dem Liga-Tab heraus gespielt werden.',
  },
  'league-cl': {
    category: 'league',
    label: 'Was ist die Champions-League-Runde?',
    title: 'Was ist die Champions-League-Runde?',
    text: 'Nach der regulären Saison ziehen die besten vier in das Champions-League-Bracket ein. Diese Partien nutzen den dedizierten CL-Pool und die für die Endrunde definierte längere Fragenzahl.',
  },
  'league-forfeit': {
    category: 'league',
    label: 'Was passiert mit unvollendeten Liga-Paarungen?',
    title: 'Was passiert mit unvollendeten Paarungen?',
    text: 'Die Liga-Logik kann offene oder abgelaufene Herausforderungssituationen über Forfeit-Handling auflösen. Das verhindert, dass die Saison dauerhaft blockiert wird, und lässt die Tabelle sauber abschließen.',
  },
  'my-progress': {
    category: 'progress',
    label: 'Was zeigt Mein Fortschritt?',
    title: 'Was zeigt Mein Fortschritt?',
    text: '„Mein Fortschritt” konzentriert sich auf deinen eigenen Status im Kurs. Es hilft dir zu sehen, was du bereits gemeistert hast, wie viele Fragen du beantwortet hast und wo du noch Schwächen hast.',
  },
  'leaderboard': {
    category: 'progress',
    label: 'Was misst die Rangliste?',
    title: 'Was misst die Rangliste?',
    text: 'Die Rangliste vergleicht Lernende im selben Kurs. Je nach Ansicht kann sie Rang, XP, Mastery und andere Aktivitätsindikatoren zeigen — so siehst du, wer gerade am aktivsten oder stärksten ist.',
  },
  'xp-levels': {
    category: 'progress',
    label: 'Wie funktionieren XP und Level?',
    title: 'Wie funktionieren XP und Level?',
    text: 'Lernsessions können XP vergeben. Mit wachsenden XP steigt dein Level und der Fortschrittsbalken bewegt sich auf den nächsten Schwellenwert zu. Das gibt dir ein langfristiges Gefühl von Schwung über Sessions hinweg.',
  },
  'streaks': {
    category: 'progress',
    label: 'Was ist ein Streak?',
    title: 'Was ist ein Streak?',
    text: 'Ein Streak zählt, wie viele Tage hintereinander du aktiv warst. Meilensteine können extra Feier-Feedback auslösen, aber der eigentliche Wert liegt darin, dass er dir hilft, langfristig konsequent zu bleiben.',
  },
  'badges': {
    category: 'progress',
    label: 'Wie funktionieren Badges?',
    title: 'Wie funktionieren Badges?',
    text: 'Badges werden freigeschaltet, wenn du bestimmte Lernbedingungen erfüllst. Du kannst sie durch Aktivität, Konsequenz oder Mastery-bezogenen Fortschritt verdienen, und die UI zeigt sowohl freigeschaltete als auch zukünftige Badges mit Fortschrittsanzeige.',
  },
  'settings-content-language': {
    category: 'settings',
    label: 'Was ändert die Inhaltssprache?',
    title: 'Was ändert die Inhaltssprache?',
    text: 'Die Inhaltssprache ändert die Sprache, die für Frage- und Antwortinhalte verwendet wird, wenn Übersetzungen verfügbar sind. Sie ändert nicht die allgemeine App-Oberflächensprache.',
  },
  'settings-ui-language': {
    category: 'settings',
    label: 'Was ist der Unterschied zwischen UI-Sprache und Inhaltssprache?',
    title: 'UI-Sprache vs. Inhaltssprache',
    text: 'Die UI-Sprache betrifft die App-Oberfläche mit Beschriftungen und Steuerungen. Die Inhaltssprache betrifft das Lernmaterial selbst, also übersetzte Frage- und Antworttexte. Du kannst die Oberfläche in einer Sprache und den Frageninhalt in einer anderen Sprache halten.',
  },
  'settings-virtuprof': {
    category: 'settings',
    label: 'Kann ich VirtuProf ausschalten?',
    title: 'Kann ich VirtuProf ausschalten?',
    text: 'Ja. In den persönlichen Einstellungen gibt es einen Virtuellen-Assistenten-Toggle. Wenn du ihn ausschaltest, erscheinen der Ecken-Avatar und seine automatischen Hinweise nicht mehr, bis du ihn wieder aktivierst.',
  },
  'settings-calendar': {
    category: 'settings',
    label: 'Wozu ist der Kalender-Sync?',
    title: 'Wozu ist der Kalender-Sync?',
    text: 'Kalender-Sync gibt dir eine ICS-URL, die du in Kalender-Apps abonnieren kannst. Sie dient dazu, fällige Karten oder Lern-Erinnerungen außerhalb der App sichtbar zu machen. Das Neuerstellen des Tokens macht die alte URL ungültig.',
  },
  'settings-notifications': {
    category: 'settings',
    label: 'Was machen die anderen persönlichen Einstellungen?',
    title: 'Was machen die anderen Einstellungen?',
    text: 'Tägliche Herausforderung, Benachrichtigungen und verwandte Toggles steuern, wie viel zusätzliche Orientierungshilfe oder Erinnerungsverhalten du bekommst. Sie schreiben deine eigentlichen Fragendaten nicht um, können aber ändern, wie die App dich hinweist und informiert.',
  },
}
