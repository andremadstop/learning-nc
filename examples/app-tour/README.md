# Example course: How to learn with this app

A short onboarding course about the Learning app itself, as two pools: **How to learn with this
app** (English) and **So lernst du mit dieser App** (German). Each has 18 questions: 12 single
choice, 4 multiple choice and 2 open answer. Every question comes with a hint, feedback per answer
option, a background fact and a key takeaway; four questions also have a spoken intro and a short
dialog after the answer (5.14.0 or later).

The course uses no IT jargon and no gamification. If you use it for mandatory training with the
course profile "Mandatory training with certificate", note that the profile switches Leitner
off: questions 3, 4, 5, 8, 16 and 18 explain Leitner, the Smart Queue and maintenance mode, which
your learners then do not see. Switch Leitner on in the course rules, or delete those questions
from your imported pool.

The `examples/` folder is not part of the app package from the App Store. Get it from the
repository or from the GitHub release.

## Import

```bash
# 1. Questions: creates both pools, owned by <uid>
php occ learning:import-pool-json examples/app-tour/pool.json --user=<uid> --dry-run
php occ learning:import-pool-json examples/app-tour/pool.json --user=<uid>

# 2. Hint, feedback, background, key takeaway, keywords and audio: one bundle per pool
php occ learning:import-question-media <id of the English pool> examples/app-tour/bundle/en --dry-run
php occ learning:import-question-media <id of the English pool> examples/app-tour/bundle/en
php occ learning:import-question-media <id of the German pool> examples/app-tour/bundle/de
```

Step 1 prints the IDs of the new pools. Step 2 matches the questions by `exam_key`
(`app-tour-NN-en`, `app-tour-NN-de`), so each pool takes the bundle of its language. Running it
again changes nothing. Then attach the pool of your language to a course.

The bundles carry audio for questions 3, 7, 11 and 13 only, to keep the repository small. Question
13, which is about audio in several languages, has German and English in both bundles. The
complete audio for all questions is attached to the GitHub release as `app-tour-media-full.zip`.
It has the same layout (`en/`, `de/`): unzip it and run step 2 with its folders instead.

## Licence

Text and audio: CC BY 4.0, André Stiebitz. See `LICENSE`.
