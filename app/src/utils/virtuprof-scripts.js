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
        text: 'Training mode shows you all questions in the pool. Just start and learn without pressure.',
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
        text: 'A duel puts you head-to-head against another learner.',
        animation: 'talk',
      },
      {
        text: 'Fast and correct answers matter: both right means speed decides the higher score bonus.',
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
        text: 'Runde {round} von {total}!',
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
        text: '{winnerName} gewinnt die Gameshow!',
        animation: 'celebrate',
      },
    ],
  },
}

export const FAQ_CATEGORIES = {
  gettingStarted: {
    label: 'Getting Started',
    title: 'Start Here',
    description: 'Core basics about courses, pools, locks and language settings.',
  },
  training: {
    label: 'Training',
    title: 'Training',
    description: 'Immediate feedback, question types and the quickest way to practice.',
  },
  leitner: {
    label: 'Leitner & Queue',
    title: 'Leitner & Queue',
    description: 'Spaced repetition, boxes, due cards and remediation.',
  },
  exam: {
    label: 'Exam',
    title: 'Exam',
    description: 'Timed sessions, review flow, tab locking and completion rules.',
  },
  duel: {
    label: 'Duell',
    title: 'Duell',
    description: 'Create, join and understand direct head-to-head matches.',
  },
  league: {
    label: 'Liga',
    title: 'Liga',
    description: 'Challenges, standings, season flow and Champions League.',
  },
  progress: {
    label: 'Progress & Rewards',
    title: 'Progress & Rewards',
    description: 'Mastery, leaderboard, XP, streaks and badges.',
  },
  settings: {
    label: 'Settings & Language',
    title: 'Settings & Language',
    description: 'Content language, assistant toggle, calendar sync and preferences.',
  },
}

export const FAQS = {
  'start-course': {
    category: 'gettingStarted',
    label: 'How do I start learning in a course?',
    title: 'How do I start learning in a course?',
    text: 'Open a course, choose a learning mode like Training or Leitner and then pick one of the assigned pools. The mode starts only after the pool selection, so the teacher controls the available material through course pools and optional filters.',
  },
  'course-vs-pool': {
    category: 'gettingStarted',
    label: 'What is the difference between a course and a pool?',
    title: 'What is the difference between a course and a pool?',
    text: 'A course is the learning space for one class or exam track. A pool is a set of questions. Courses can contain several pools, and the same pool can be reused in different contexts without rewriting the questions.',
  },
  'pool-locked': {
    category: 'gettingStarted',
    label: 'Why is a pool locked?',
    title: 'Why is a pool locked?',
    text: 'A pool is locked when the course has at least one required and enforced pool that you have not completed yet. In that case, optional pools stay unavailable until every filtered question in the required pool was answered at least once.',
  },
  'fewer-questions': {
    category: 'gettingStarted',
    label: 'Why does a pool show fewer questions than expected?',
    title: 'Why does a pool show fewer questions than expected?',
    text: 'A course can apply filters to a pool without changing the original pool. Typical filters are exam key, chapter key or a fixed list of question IDs. That is why a course pool can show fewer questions than the raw pool total.',
  },
  'content-language': {
    category: 'gettingStarted',
    label: 'Why do I see translated content?',
    title: 'Why do I see translated content?',
    text: 'If Content Language is set in Personal Settings, Learning tries to show translated question and answer texts in that language. If no translation exists, the original content is used.',
  },
  'training-overview': {
    category: 'training',
    label: 'What is Training mode for?',
    title: 'What is Training mode for?',
    text: 'Training is the low-pressure practice mode. You answer one question at a time, get feedback immediately and can repeat the pool as often as you want. It is best for quick drilling, familiarization and first passes through a topic.',
  },
  'training-feedback': {
    category: 'training',
    label: 'What feedback do I get in Training?',
    title: 'What feedback do I get in Training?',
    text: 'After each answer you see whether it was correct, which answer was right, and when available also an explanation or instructor note. That makes Training the fastest mode for learning from mistakes immediately.',
  },
  'training-question-types': {
    category: 'training',
    label: 'Which question types can appear in Training?',
    title: 'Which question types can appear in Training?',
    text: 'Training can include classic single-choice questions, multiple-correct questions, free-text questions and PBQs. Multi-select questions need all correct options, free-text questions compare against a model answer, and PBQs use an interactive task component.',
  },
  'training-explain': {
    category: 'training',
    label: 'What does “Explain this” do?',
    title: 'What does “Explain this” do?',
    text: 'If the explain feature is available for your app setup, Training can request a short explanation for the current question after you answered it. This is meant as extra support, not as the authoritative source over the actual solution and instructor notes.',
  },
  'training-results': {
    category: 'training',
    label: 'What do I see after Training ends?',
    title: 'What do I see after Training ends?',
    text: 'At the end you see your score, the number of correct answers, XP gained, streak effects and possible badge unlocks. Training may also compare your result with your average and mark a personal best.',
  },
  'leitner-overview': {
    category: 'leitner',
    label: 'How does the Leitner system work?',
    title: 'How does the Leitner system work?',
    text: 'Leitner uses spaced repetition with 5 boxes. Correct answers move a card into a higher box, wrong answers send it back. Higher boxes come back less often, so the system focuses your time on weak material.',
  },
  'leitner-due': {
    category: 'leitner',
    label: 'What does “due” mean in Leitner?',
    title: 'What does “due” mean in Leitner?',
    text: 'A due card is ready for review again based on its current box and schedule. If no cards are due, the dashboard shows that you are caught up for now.',
  },
  'smart-queue': {
    category: 'leitner',
    label: 'What is Smart Queue?',
    title: 'What is Smart Queue?',
    text: 'Smart Queue combines due Leitner questions across pools and sorts them by priority, with the most urgent items first. It is useful when you want one focused review list instead of opening pools one by one.',
  },
  'remediation': {
    category: 'leitner',
    label: 'What are Trouble Spots?',
    title: 'What are Trouble Spots?',
    text: 'Trouble Spots is the remediation view. It collects questions that currently cause problems, so you can revisit weak points directly instead of running the full queue.',
  },
  'leitner-pool-vs-queue': {
    category: 'leitner',
    label: 'When should I use Leitner, Smart Queue or Training?',
    title: 'When should I use Leitner, Smart Queue or Training?',
    text: 'Use Training when you want broad, low-pressure practice inside one pool. Use Leitner when you want structured spaced repetition for one pool. Use Smart Queue when you want due questions from several pools in one prioritized review session.',
  },
  'exam-overview': {
    category: 'exam',
    label: 'What is different in Exam mode?',
    title: 'What is different in Exam mode?',
    text: 'Exam mode behaves like a test run: you choose a time limit and question count, complete one continuous session, and do not get immediate correctness feedback while answering. The detailed review comes only after the exam ends.',
  },
  'exam-time-limit': {
    category: 'exam',
    label: 'What happens when time runs out?',
    title: 'What happens when time runs out?',
    text: 'If the time limit expires, the exam is auto-submitted by the server. You are then taken to the results and detailed review instead of continuing the question phase.',
  },
  'exam-tab-lock': {
    category: 'exam',
    label: 'Why is an exam read-only in another tab?',
    title: 'Why is an exam read-only in another tab?',
    text: 'Learning protects active exams from being answered in multiple tabs at the same time. If the exam is already active elsewhere, another tab can only show the state in read-only mode.',
  },
  'exam-abort-end': {
    category: 'exam',
    label: 'What is the difference between End Exam and Abort?',
    title: 'What is the difference between End Exam and Abort?',
    text: 'End Exam finishes the current attempt and saves the result based on what you answered so far. Abort discards the attempt instead, so no regular result is kept.',
  },
  'exam-review': {
    category: 'exam',
    label: 'What do I see in the exam review?',
    title: 'What do I see in the exam review?',
    text: 'The review shows your score, correct and wrong counts, time taken, questions per minute and a question-by-question breakdown. Depending on the question type, you can also see your own answer, the correct answer and instructor notes.',
  },
  'duel-create-join': {
    category: 'duel',
    label: 'How do duels start?',
    title: 'How do duels start?',
    text: 'A duel starts either by creating a new duel and sharing the code, or by joining an existing duel with that code. Both players then enter the lobby and have to mark themselves ready before the first question begins.',
  },
  'duel-pool-questions': {
    category: 'duel',
    label: 'Who chooses the pool and number of questions?',
    title: 'Who chooses the pool and number of questions?',
    text: 'The player who creates the duel chooses the pool and the number of questions. The opponent joins that exact setup through the duel code.',
  },
  'duel-scoring': {
    category: 'duel',
    label: 'How is duel scoring decided?',
    title: 'How is duel scoring decided?',
    text: 'Correct answers are what matter first. If both players answer a question correctly, speed can decide the better outcome. After each question the score bar updates, so you can see the duel swing live.',
  },
  'duel-feedback': {
    category: 'duel',
    label: 'Why do I wait for the opponent after answering?',
    title: 'Why do I wait for the opponent after answering?',
    text: 'After you answer, your choice stays visible and the question waits until the opponent also finishes that round. Then both players get the short feedback screen before the next question starts.',
  },
  'duel-rematch': {
    category: 'duel',
    label: 'What happens after a duel ends?',
    title: 'What happens after a duel ends?',
    text: 'After the final score, you see the winner and can start a rematch. If a duel was league-linked, the result is also written back into the league standings.',
  },
  'league-overview': {
    category: 'league',
    label: 'How does the league work?',
    title: 'How does the league work?',
    text: 'The league exists inside one course. Learners challenge each other, complete linked duels and earn points in a standings table. The season also has a separate Champions League pool for the final round.',
  },
  'league-standings': {
    category: 'league',
    label: 'How are league standings calculated?',
    title: 'How are league standings calculated?',
    text: 'The table tracks played matches, wins, losses, points and point difference. Points decide the ranking first, and point difference serves as the tie-breaker.',
  },
  'league-challenge-flow': {
    category: 'league',
    label: 'How do league challenges work?',
    title: 'How do league challenges work?',
    text: 'You challenge an opponent from the standings, the opponent accepts it, and the system creates the linked duel. Incoming and outgoing challenges are shown separately so you can see what still needs action.',
  },
  'league-open-duel': {
    category: 'league',
    label: 'When does “Open Duel” appear in league play?',
    title: 'When does “Open Duel” appear in league play?',
    text: 'Open Duel appears once a challenge has been accepted and a duel code already exists. At that point the match is ready to be played from inside the league tab itself.',
  },
  'league-cl': {
    category: 'league',
    label: 'What is the Champions League round?',
    title: 'What is the Champions League round?',
    text: 'After the regular season, the top four move into the Champions League bracket. Those matches use the dedicated CL pool and the longer question count defined for the final round.',
  },
  'league-forfeit': {
    category: 'league',
    label: 'What happens to unfinished league pairings?',
    title: 'What happens to unfinished league pairings?',
    text: 'League logic can resolve unfinished or expired challenge situations through forfeit handling. That prevents the season from being blocked forever and lets the standings close cleanly.',
  },
  'my-progress': {
    category: 'progress',
    label: 'What does My Progress show?',
    title: 'What does My Progress show?',
    text: 'My Progress focuses on your own status inside the course. It helps you see what you already mastered, how many questions you answered and where you still have weak areas.',
  },
  'leaderboard': {
    category: 'progress',
    label: 'What is the leaderboard measuring?',
    title: 'What is the leaderboard measuring?',
    text: 'The leaderboard compares learners in the same course. Depending on the view, it can show rank, XP, mastery and other activity indicators so you can see who is currently most active or strongest.',
  },
  'xp-levels': {
    category: 'progress',
    label: 'How do XP and levels work?',
    title: 'How do XP and levels work?',
    text: 'Learning sessions can award XP. As XP grows, your level increases and your progress bar moves toward the next threshold. This gives you a long-term sense of momentum across sessions.',
  },
  'streaks': {
    category: 'progress',
    label: 'What is a streak?',
    title: 'What is a streak?',
    text: 'A streak tracks how many days in a row you stayed active. Milestones can trigger extra celebration feedback, but the core value is that it helps you stay consistent over time.',
  },
  'badges': {
    category: 'progress',
    label: 'How do badges work?',
    title: 'How do badges work?',
    text: 'Badges unlock when you meet certain learning conditions. You can earn them through activity, consistency or mastery-related progress, and the UI shows both unlocked badges and progress toward future ones.',
  },
  'settings-content-language': {
    category: 'settings',
    label: 'What does Content Language change?',
    title: 'What does Content Language change?',
    text: 'Content Language changes the language used for question and answer content when translations are available. It does not change the general app interface language by itself.',
  },
  'settings-ui-language': {
    category: 'settings',
    label: 'What is the difference between UI Language and Content Language?',
    title: 'What is the difference between UI Language and Content Language?',
    text: 'UI Language affects the app interface labels and controls. Content Language affects the learning material itself, such as translated question text and answers. You can keep the interface in one language and the question content in another.',
  },
  'settings-virtuprof': {
    category: 'settings',
    label: 'Can I turn VirtuProf off?',
    title: 'Can I turn VirtuProf off?',
    text: 'Yes. Personal Settings includes a Virtual assistant toggle. If you switch it off, the corner avatar and its automatic hints stop appearing until you enable it again.',
  },
  'settings-calendar': {
    category: 'settings',
    label: 'What is Calendar Sync for?',
    title: 'What is Calendar Sync for?',
    text: 'Calendar Sync gives you an ICS URL that can be subscribed to in calendar apps. It is meant to surface due cards or study reminders outside the app. Regenerating the token invalidates the old URL.',
  },
  'settings-notifications': {
    category: 'settings',
    label: 'What do the other personal settings do?',
    title: 'What do the other personal settings do?',
    text: 'Daily Challenge, notifications and related toggles control how much extra guidance or reminder behavior you get. They do not rewrite your actual question data, but they can change how the app nudges and informs you.',
  },
}
