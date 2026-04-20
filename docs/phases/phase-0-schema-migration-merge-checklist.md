# Phase 0 Schema Migration — Pre-merge Smoke Test

Before merging this branch into `main`:

## Automated (must pass in CI)

- [ ] `./vendor/bin/pest` — full suite green
- [ ] `./vendor/bin/pest tests/Feature/Regression/HotelSpaRegressionTest.php` — green
- [ ] `./vendor/bin/pest tests/Feature/SchemaRollbackTest.php` — green

## Manual hotel SPA walkthrough (http://avatar.local)

For each of the four hotel agents (Sofia, Elena, Marco, Hans):

- [ ] Agent list loads with avatar image and role
- [ ] Open chat — starter prompts render
- [ ] Send a text message — reply streams and finishes
- [ ] Start voice recording, stop, transcript appears (mic permission, HTTPS/localhost required)
- [ ] Click TTS on an agent reply — audio plays
- [ ] Attachments panel opens (both chat files and avatar files)
- [ ] Rename and delete conversation work
- [ ] HeyGen voice-mode button toggles (expected to show error overlay — v1 API is sunset; this is pre-existing and not a regression)

## Database

- [ ] `SELECT COUNT(*) FROM agents WHERE vertical_id IS NULL` returns 0
- [ ] `SELECT COUNT(*) FROM conversations WHERE vertical_id IS NULL` returns 0
- [ ] `SELECT COUNT(*) FROM messages WHERE role='agent' AND agent_id IS NULL` returns 0
- [ ] `SELECT slug FROM verticals ORDER BY id` returns `hotel, wellness`
- [ ] `SELECT slug FROM subscription_plans ORDER BY id` returns `free, basic, pro, ultimate`
- [ ] `SELECT extname FROM pg_extension WHERE extname='vector'` returns one row

## Sign-off

- [ ] Engineer name + date: __________
- [ ] Code reviewer: __________
