# API Contracts

This document defines stable response shapes used by the frontend.

## `GET /apps/learning/api/courses/{courseId}/progress`

Purpose:
- Returns course progress rows for instructors (paged and sortable), or own row for students.

Query params:
- `limit` (int, optional, default `25`, max `100`)
- `offset` (int, optional, default `0`)
- `sortKey` (`user_id|current_level|total_xp|overall_mastery|last_activity_date`)
- `sortDir` (`asc|desc`)

Response:
```json
{
  "students": [
    {
      "user_id": "student01",
      "display_name": "Student 01",
      "total_xp": 345,
      "current_level": 3,
      "overall_mastery": 42,
      "last_activity_date": "2026-03-10 14:22:00",
      "pools": [
        {
          "pool_id": 123,
          "pool_name": "CompTIA Network+ (DE)",
          "total_questions": 100,
          "mastered": 25,
          "answered": 62,
          "accuracy": 74,
          "last_active": 1710176400
        }
      ]
    }
  ],
  "meta": {
    "total": 87,
    "limit": 25,
    "offset": 0,
    "sort_key": "total_xp",
    "sort_dir": "desc"
  }
}
```

## `GET /apps/learning/api/courses/{courseId}/my-progress`

Purpose:
- Returns progress for the current user in one course, independent of role.

Response:
```json
{
  "pools": [
    {
      "pool_id": 123,
      "pool_name": "CompTIA Network+ (DE)",
      "total_questions": 100,
      "mastered": 25,
      "answered": 62,
      "accuracy": 74,
      "last_active": 1710176400
    }
  ]
}
```

Notes:
- `mastered` = count of Leitner items in box 5 for that pool.
- `answered` = sum of `total_questions` from completed sessions in that pool.
- `accuracy` = rounded percentage from completed session totals.

## `POST /apps/learning/api/training/answer`

Purpose:
- Submit one answer in an active training session.

Normal response:
```json
{
  "is_correct": true,
  "correct_answer_text": "Example",
  "correct_answer_ids": [1],
  "correct_answer_texts": ["Example"],
  "xp_earned": 5
}
```

Suppressed response (active exam integrity path):
```json
{
  "recorded": true,
  "suppressed": true
}
```

Notes:
- In suppressed mode, no correctness details and no immediate XP are returned.

## `POST /apps/learning/api/training/submitBatch`

Purpose:
- Submit multiple answers for one session (exam or batch training flow).

Response:
```json
[
  {
    "questionId": 42,
    "is_correct": false,
    "correct_answer_texts": ["A", "C"],
    "xp_earned": 0
  }
]
```

Suppressed item shape:
```json
{
  "questionId": 42,
  "recorded": true,
  "suppressed": true
}
```

## `GET /apps/learning/api/settings/admin`

Response includes:
```json
{
  "daily_challenge_enabled": "yes|no",
  "default_language": "de|en",
  "max_import_size_mb": 2,
  "gamification_enabled": "yes|no",
  "allow_course_instructor_fallback": "yes|no"
}
```

## `PUT /apps/learning/api/settings/admin`

Accepted payload keys:
- `daily_challenge_enabled`
- `default_language`
- `max_import_size_mb`
- `gamification_enabled`
- `allow_course_instructor_fallback`

Values:
- booleans are represented as `"yes"` / `"no"` for compatibility.

## `POST /apps/learning/api/pools/{poolId}/import/json`

Optional wrapper metadata:
```json
{
  "_meta": {
    "source": "FISI Training",
    "license": "internal-training-use"
  },
  "questions": [ ... ]
}
```

Response may include:
```json
{
  "imported": 42,
  "errors": [],
  "total_items": 50,
  "meta": {
    "source": "FISI Training",
    "license": "internal-training-use"
  }
}
```
