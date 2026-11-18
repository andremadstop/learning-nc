# Question media fixture (GitHub #66)

A generic bundle in the format `learning-question-media/1` for the tests of
`occ learning:import-question-media` and for the persona seed. No third-party content.

- `pool.json` — two questions with `exam_key` `demo-q1` (four options) and `demo-q2`
  (three options, two of them with the same text), importable with `occ learning:import-pool-json`.
- `bundle/` — `manifest.json`, short sine tones as mp3 (generated with ffmpeg, 24 kHz mono,
  32 kbit/s), their timelines and the cues of `demo-q1`.
- `bundle-v2/` — format `learning-question-media/2`: a spoken explanation per answer option
  (`<exam_key>/opt-<i>-<lang>.mp3` + `{duration, role, text}`), all four options of `demo-q1`
  (option B also in German) and only the right option of `demo-q2` (so a test can show that
  nothing about the option voices is visible before answering). 0.2 s sine tones, made with
  `ffmpeg -f lavfi -i "sine=frequency=<f>:duration=0.2" -ac 1 -ar 24000 -b:a 32k -map_metadata -1
  -id3v2_version 0 -write_xing 0`, invented texts. No intro or dialog in it: imported after
  `bundle/`, it leaves those alone.
