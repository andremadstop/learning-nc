# API Contracts

This document defines stable response shapes used by the frontend.

## `POST /apps/learning/api/training/start`

Purpose:
- Starts training or exam sessions and returns question payload.

Request payload keys:
- `poolId` (int, required)
- `mode` (`training|exam`, optional, default `training`)
- `limit` (int, optional)
- `timeLimitSeconds` (int, optional, exam only)

Response excerpt:
```json
{
  "session_id": 321,
  "mode": "exam",
  "total_questions": 20,
  "server_time": 1741700000,
  "time_limit_seconds": 600,
  "exam_deadline_at": 1741700600,
  "attempt_no": 2,
  "resumed": false,
  "answered": {
    "101": 4,
    "102": [10, 12],
    "103": { "answerText": "example" }
  },
  "questions": []
}
```

## `POST /apps/learning/api/training/complete`

Response excerpt:
```json
{
  "session_id": 321,
  "total_questions": 20,
  "correct_answers": 15,
  "score_percentage": 75,
  "timed_out": false,
  "exam_deadline_at": 1741700600,
  "attempt_no": 2
}
```

## `GET /apps/learning/api/training/session/{sessionId}`

Purpose:
- Returns live server status for a running session (used for exam timeout synchronization and multi-tab resilience).

Response excerpt:
```json
{
  "session_id": 321,
  "pool_id": 77,
  "mode": "exam",
  "server_time": 1741700100,
  "started_at": 1741700000,
  "completed_at": null,
  "completed": false,
  "timed_out": false,
  "time_limit_seconds": 600,
  "exam_deadline_at": 1741700600,
  "remaining_seconds": 500,
  "attempt_no": 2,
  "total_questions": 20,
  "correct_answers": 0,
  "answered": {
    "101": 4
  }
}
```

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

## `GET /apps/learning/api/courses/{courseId}/leaderboard`

Purpose:
- Returns leaderboard rows (paged, sortable, optional active-only filter).

Query params:
- `limit` (int, optional, default `25`, max `100`)
- `offset` (int, optional, default `0`)
- `sortKey` (`user_id|total_xp|current_level|total_mastered|current_streak|total_sessions|last_activity_date`)
- `sortDir` (`asc|desc`)
- `activeOnly` (`0|1`, optional, default `0`)
- `activeWithinDays` (int, optional, default `30`)

Response:
```json
{
  "leaderboard": [
    {
      "rank": 1,
      "display_name": "Student 01",
      "total_xp": 2500,
      "current_level": 9,
      "total_mastered": 180
    }
  ],
  "my_rank": 14,
  "meta": {
    "total": 87,
    "limit": 25,
    "offset": 0,
    "sort_key": "total_xp",
    "sort_dir": "desc",
    "active_only": false,
    "active_within_days": 30
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
  "allow_course_instructor_fallback": "yes|no",
  "exam_attempt_limit_per_day": 5,
  "exam_attempt_cooldown_minutes": 10
}
```

## `GET /apps/learning/api/settings/admin/audit`

Purpose:
- Returns persisted audit events for admins.

Response excerpt:
```json
{
  "events": [
    {
      "id": 1,
      "event_key": "exam.start",
      "user_id": "student01",
      "session_id": 321,
      "pool_id": 77,
      "created_at": 1741700000,
      "context": {}
    }
  ],
  "limit": 100,
  "offset": 0
}
```

## `PUT /apps/learning/api/settings/admin`

Accepted payload keys:
- `daily_challenge_enabled`
- `default_language`
- `max_import_size_mb`
- `gamification_enabled`
- `allow_course_instructor_fallback`
- `exam_attempt_limit_per_day`
- `exam_attempt_cooldown_minutes`

Values:
- booleans are represented as `"yes"` / `"no"` for compatibility.

## `PUT /apps/learning/api/pools/{id}/review`
## `PUT /apps/learning/api/questions/{id}/review`

Purpose:
- Updates editorial review state (`draft|reviewed|published`) for pool/question content.

Accepted payload keys:
- `reviewStatus` (string, required)
- `reviewerId` (string, optional; defaults to current user)

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
