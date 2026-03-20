# Distribution Checklist

## Pre-Launch (Manual Steps)

- [ ] Create screenshots in browser (3 minimum):
  1. Pool list view with multiple pools
  2. Training mode with question and answers
  3. Leitner box view with stats
- [ ] Create App Store account at https://apps.nextcloud.com
- [ ] Request signing certificate
- [ ] Sign app: `occ integrity:sign-app --privateKey=... --certificate=...`
- [ ] Upload tarball to App Store
- [ ] Wait for review feedback

## Launch Day

- [ ] Verify app appears in App Store search
- [ ] Post on Nextcloud Forum (help.nextcloud.com):
  - Category: Apps
  - Title: "Learning — Spaced Repetition with Leitner System"
  - Link to App Store listing
  - Include 1 screenshot

- [ ] Post on Reddit:
  - r/selfhosted: "I built a self-hosted spaced repetition app for Nextcloud"
  - r/nextcloud: "New app: Learning — Flashcards with Leitner System"
  - Include features list and link

- [ ] Post on Hacker News:
  - Title: "Show HN: Learning — Spaced Repetition for Nextcloud (AGPL-3.0)"
  - Link to GitHub repo

- [ ] Update GitHub repo:
  - Add badges (Nextcloud version, PHP version, license)
  - Create GitHub Release with tarball
  - Enable GitHub Issues

## Post-Launch (Week 1)

- [ ] Monitor App Store reviews
- [ ] Monitor GitHub issues
- [ ] Respond to forum/Reddit feedback
- [ ] Check NC Forum for bug reports
- [ ] Collect feature requests for v1.1.0
