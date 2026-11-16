# Question media fixture (GitHub #66)

A generic bundle in the format `learning-question-media/1` for the tests of
`occ learning:import-question-media` and for the persona seed. No third-party content.

- `pool.json` — two questions with `exam_key` `demo-q1` (four options) and `demo-q2`
  (three options, two of them with the same text), importable with `occ learning:import-pool-json`.
- `bundle/` — `manifest.json`, short sine tones as mp3 (generated with ffmpeg, 24 kHz mono,
  32 kbit/s), their timelines and the cues of `demo-q1`.
