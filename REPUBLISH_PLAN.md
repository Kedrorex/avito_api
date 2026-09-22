# Plan: Republishing via AutoLoad Feeds

## Problem Statement

1. **Views vs uniq_views**: Avito API reports `views` always as 0, but `uniqViews` contains actual data.
   Current code uses `views` for candidate detection — this is incorrect.
   **Fix**: Use `uniq_views` to determine zero-view ads.

2. **Empty republish_candidates table**: Because of (1), no candidates are collected.
   Once (1) is fixed, candidates will be populated.

3. **No feed-based republishing**: Current `republishBatch()` calls Avito API directly.
   We need a feed-based approach for batch republishing.

---

## Target Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    REPUBLISH PIPELINE                            │
│                                                                  │
│  1. COLLECT CANDIDATES                                           │
│     ├── Collect stats (uniq_views) from Avito API               │
│     ├── Find ads with 0 uniq_views over N days                  │
│     ├── Insert into republish_candidates_YYYY_MM                │
│     └── Update status → 'low_perf'                              │
│                                                                  │
│  2. GENERATE FEED #1 (DEACTIVATE) — e.g. at 03:00               │
│     ├── Select up to 70 candidates from republish_candidates    │
│     ├── Generate TSV with operation="remove" for each           │
│     ├── Upload to cloud storage (future)                        │
│     └── Send command to Avito: "load feed from cloud"           │
│     └── Avito removes selected ads from publication             │
│                                                                  │
│  3. WAIT (e.g. 1 hour)                                           │
│                                                                  │
│  4. GENERATE FEED #2 (REACTIVATE) — e.g. at 04:00               │
│     ├── Select same candidates (now deactivated)                │
│     ├── Generate TSV with operation="update" + full ad data     │
│     ├── Upload to cloud storage (future)                        │
│     └── Send command to Avito: "load feed from cloud"           │
│     └── Avito reactivates selected ads as new generations       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Fix Candidate Detection (Current)

### Issue
`ItemRepository::findZeroViewCandidates()` uses `SUM(s.views) = 0`.
Avito API returns `views=0` always, but `uniqViews` has real data.

### Fix
Change the SQL query to use `uniq_views` instead of `views`:
```sql
-- Before:
SELECT COALESCE(SUM(s.views), 0) FROM (...) s

-- After:
SELECT COALESCE(SUM(s.uniq_views), 0) FROM (...) s
```

### Files Modified
- `src/Repositories/ItemRepository.php` — `findZeroViewCandidates()`

---

## Phase 2: Generate 2 Feeds for Republishing (Current)

### Feed #1: Deactivate (Remove from publication)
- **Format**: TSV (same columns as regular feed)
- **operation**: `remove`
- **Content**: Only `id` (unique identifier) for each candidate
- **Purpose**: Tells Avito to remove these ads from publication

### Feed #2: Reactivate (Update with new data)
- **Format**: TSV (same columns as regular feed)
- **operation**: `update`
- **Content**: Full ad data for each candidate (same as regular feed)
- **Purpose**: Tells Avito to reactivate ads with updated data (new generation)

### Flow
```
User requests: "Generate feeds for republishing N candidates"
    │
    ├─► Get N candidates from republish_candidates_YYYY_MM
    │   (up to 70, respecting max_daily_repub limit)
    │
    ├─► Generate Feed #1 (deactivate)
    │   └─► fid/avito_feed_deactivate_YYYY-MM-DD_HH-mm-ss.tsv
    │
    ├─► Generate Feed #2 (reactivate)
    │   └─► fid/avito_feed_reactivate_YYYY-MM-DD_HH-mm-ss.tsv
    │
    └─► Return paths + counts for user verification
```

### Files to Create/Modify
- **New**: `src/Services/RepublishFeedService.php`
- **Modify**: `src/Controllers/AvitoController.php` — add `generateRepublishFeeds()`
- **Modify**: `routes/api.php` — add HTTP route
- **Modify**: `index.php` — add CLI command

---

## Phase 3: Cloud Upload & Avito Command (Future)

### Cloud Upload
- Upload TSV to cloud storage (Yandex Object Storage / S3-compatible)
- Store feed URL for Avito AutoLoad

### Avito AutoLoad Command
- Call Avito API to trigger feed reload
- `POST /autoload/v1/accounts/{user_id}/feed/upload`
- Or use the appropriate endpoint for triggering auto-load

---

## Phase 4: Scheduling & Automation (Future)

- Schedule Feed #1 generation at 03:00
- Schedule Feed #2 generation at 04:00
- Automated cloud upload + Avito command
- Monitor feed processing status

---

## ✅ Completed: Phase 1 + Phase 2

### Phase 1: Fix Candidate Detection ✅
- **Issue**: `findZeroViewCandidates()` used `SUM(s.views) = 0`. Avito API returns `views=0` always, but `uniqViews` has real data.
- **Fix**: Changed to `SUM(s.uniq_views) = 0` in `ItemRepository::findZeroViewCandidates()`
- **Additional fix**: Removed `published_at IS NOT NULL` check (was NULL for all ads)
- **Result**: 8032 candidates found and added to `republish_candidates_2026_09`

### Phase 2: Generate 2 Feeds for Republishing ✅
- **Created**: `src/Services/RepublishFeedService.php`
- **CLI command**: `php index.php republish-feeds <count>`
- **HTTP endpoint**: `POST /republish-feeds` with `{"count": 10}`
- **Feed #1**: `avito_feed_deactivate_YYYY-MM-DD_HH-mm-ss.tsv` (operation="remove")
- **Feed #2**: `avito_feed_reactivate_YYYY-MM-DD_HH-mm-ss.tsv` (operation="update" + full data)

### Test Results
```
Всего кандидатов: 8032
Feed #1 (deactivate): avito_feed_deactivate_2026-09-16_125059.tsv (3 объявлений)
Feed #2 (reactivate): avito_feed_reactivate_2026-09-16_125059.tsv (3 объявлений)
```

**Feed #1 (deactivate)**:
```
Уникальный идентификатор объявления	Номер объявления на Авито	operation
deactivate_8012122614	8012122614	remove
deactivate_8012122223	8012122223	remove
```

**Feed #2 (reactivate)**:
```
Уникальный идентификатор объявления	Номер объявления на Авито	operation	Категория	Описание объявления	Название объявления	Цена	...
reactivate_8012122614	8012122614	update	Запчасти и аксессуары	Двигатель Hyundai Matrix G4ED в наличии	Двигатель Hyundai Matrix G4ED в наличии	108000	...
```

### Next steps:
1. Implement cloud upload
2. Implement Avito AutoLoad trigger
3. Add scheduling
