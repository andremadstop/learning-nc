<?php
return [
    'routes' => [
        // Pages
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Pools
        ['name' => 'pool#index', 'url' => '/api/pools', 'verb' => 'GET'],
        ['name' => 'pool#show', 'url' => '/api/pools/{id}', 'verb' => 'GET'],
        ['name' => 'pool#create', 'url' => '/api/pools', 'verb' => 'POST'],
        ['name' => 'pool#update', 'url' => '/api/pools/{id}', 'verb' => 'PUT'],
        ['name' => 'pool#review', 'url' => '/api/pools/{id}/review', 'verb' => 'PUT'],
        ['name' => 'pool#destroy', 'url' => '/api/pools/{id}', 'verb' => 'DELETE'],

        // Questions
        ["name" => "question#search", "url" => "/api/questions/search", "verb" => "GET"],
        ['name' => 'question#index', 'url' => '/api/pools/{poolId}/questions', 'verb' => 'GET'],
        ['name' => 'question#show', 'url' => '/api/questions/{id}', 'verb' => 'GET'],
        ['name' => 'question#create', 'url' => '/api/questions', 'verb' => 'POST'],
        ['name' => 'question#update', 'url' => '/api/questions/{id}', 'verb' => 'PUT'],
        ['name' => 'question#review', 'url' => '/api/questions/{id}/review', 'verb' => 'PUT'],
        ['name' => 'question#destroy', 'url' => '/api/questions/{id}', 'verb' => 'DELETE'],

        // Training
        ['name' => 'training#start', 'url' => '/api/training/start', 'verb' => 'POST'],
        ['name' => 'training#answer', 'url' => '/api/training/answer', 'verb' => 'POST'],
        ['name' => 'training#submitBatch', 'url' => '/api/training/submitBatch', 'verb' => 'POST'],
        ['name' => 'training#complete', 'url' => '/api/training/complete', 'verb' => 'POST'],
        ['name' => 'training#status', 'url' => '/api/training/session/{sessionId}', 'verb' => 'GET'],
        ['name' => 'training#abort', 'url' => '/api/training/abort', 'verb' => 'POST'],

        // Leitner
        ['name' => 'leitner#queue', 'url' => '/api/leitner/queue', 'verb' => 'GET'],
        ['name' => 'leitner#queueCount', 'url' => '/api/leitner/queue/count', 'verb' => 'GET'],
        ['name' => 'leitner#remediation', 'url' => '/api/leitner/remediation', 'verb' => 'GET'],
        ['name' => 'leitner#remediationCount', 'url' => '/api/leitner/remediation/count', 'verb' => 'GET'],
        ['name' => 'leitner#initialize', 'url' => '/api/leitner/initialize', 'verb' => 'POST'],
        ['name' => 'leitner#due', 'url' => '/api/leitner/due', 'verb' => 'GET'],
        ['name' => 'leitner#answer', 'url' => '/api/leitner/answer', 'verb' => 'POST'],
        ['name' => 'leitner#stats', 'url' => '/api/leitner/stats', 'verb' => 'GET'],
        ['name' => 'leitner#streak', 'url' => '/api/streak', 'verb' => 'GET'],
        ['name' => 'leitner#badges', 'url' => '/api/badges', 'verb' => 'GET'],
        ['name' => 'leitner#badgeProgress', 'url' => '/api/badges/progress', 'verb' => 'GET'],

        // Sharing
        ['name' => 'share#index', 'url' => '/api/pools/{poolId}/shares', 'verb' => 'GET'],
        ['name' => 'share#sharedWithMe', 'url' => '/api/shared', 'verb' => 'GET'],
        ['name' => 'share#create', 'url' => '/api/pools/{poolId}/shares', 'verb' => 'POST'],
        ['name' => 'share#update', 'url' => '/api/pools/{poolId}/shares/{sharedWith}', 'verb' => 'PUT'],
        ['name' => 'share#destroy', 'url' => '/api/pools/{poolId}/shares/{sharedWith}', 'verb' => 'DELETE'],

        // Images
        ['name' => 'image#upload', 'url' => '/api/questions/{questionId}/image', 'verb' => 'POST'],
        ['name' => 'image#serve', 'url' => '/api/questions/{questionId}/image', 'verb' => 'GET'],
        ['name' => 'image#delete', 'url' => '/api/questions/{questionId}/image', 'verb' => 'DELETE'],

        // Translations
        ['name' => 'translation#questionTranslations', 'url' => '/api/questions/{questionId}/translations', 'verb' => 'GET'],
        ['name' => 'translation#translatedQuestion', 'url' => '/api/questions/{questionId}/translated', 'verb' => 'GET'],
        ['name' => 'translation#setQuestionTranslation', 'url' => '/api/questions/{questionId}/translations/{lang}', 'verb' => 'PUT'],
        ['name' => 'translation#deleteQuestionTranslation', 'url' => '/api/questions/{questionId}/translations/{lang}', 'verb' => 'DELETE'],
        ['name' => 'translation#answerTranslations', 'url' => '/api/answers/{answerId}/translations', 'verb' => 'GET'],
        ['name' => 'translation#setAnswerTranslation', 'url' => '/api/answers/{answerId}/translations/{lang}', 'verb' => 'PUT'],
        ['name' => 'translation#deleteAnswerTranslation', 'url' => '/api/answers/{answerId}/translations/{lang}', 'verb' => 'DELETE'],

        // Import
        ['name' => 'import#importCsv', 'url' => '/api/pools/{poolId}/import/csv', 'verb' => 'POST'],
        ['name' => 'import#importJson', 'url' => '/api/pools/{poolId}/import/json', 'verb' => 'POST'],
        ['name' => 'import#importFile', 'url' => '/api/pools/{poolId}/import/file', 'verb' => 'POST'],

        // Export
        ['name' => 'export#exportCsv', 'url' => '/api/pools/{poolId}/export/csv', 'verb' => 'GET'],
        ['name' => 'export#exportJson', 'url' => '/api/pools/{poolId}/export/json', 'verb' => 'GET'],
        ['name' => 'export#exportIcs', 'url' => '/api/leitner/schedule.ics', 'verb' => 'GET'],
        ['name' => 'export#getCalendarToken', 'url' => '/api/v1/user/calendar-token', 'verb' => 'GET'],
        ['name' => 'export#regenerateCalendarToken', 'url' => '/api/v1/user/calendar-token/regenerate', 'verb' => 'POST'],
        ['name' => 'export#exportIcsPublic', 'url' => '/api/v1/calendar/{token}.ics', 'verb' => 'GET'],

        // User State (consolidated endpoint)
        ['name' => 'user_state#state', 'url' => '/api/v1/user/state', 'verb' => 'GET'],
        ['name' => 'user_state#missions', 'url' => '/api/v1/missions', 'verb' => 'GET'],
        ['name' => 'user_state#claimMission', 'url' => '/api/v1/missions/{missionKey}/claim', 'verb' => 'POST'],
        ['name' => 'user_state#updateSettings', 'url' => '/api/v1/user/settings', 'verb' => 'PUT'],
        ['name' => 'user_state#dailyChallenge', 'url' => '/api/v1/daily-challenge', 'verb' => 'GET'],
        ['name' => 'user_state#answerChallenge', 'url' => '/api/v1/daily-challenge/answer', 'verb' => 'POST'],

        // Settings
        ['name' => 'settings#getAdmin', 'url' => '/api/settings/admin', 'verb' => 'GET'],
        ['name' => 'settings#getAdminAudit', 'url' => '/api/settings/admin/audit', 'verb' => 'GET'],
        ['name' => 'settings#saveAdmin', 'url' => '/api/settings/admin', 'verb' => 'PUT'],
        ['name' => 'settings#getPersonal', 'url' => '/api/settings/personal', 'verb' => 'GET'],
        ['name' => 'settings#savePersonal', 'url' => '/api/settings/personal', 'verb' => 'PUT'],

        // VirtuProf
        ['name' => 'virtuProf#getState', 'url' => '/api/virtuprof/state', 'verb' => 'GET'],
        ['name' => 'virtuProf#dismiss', 'url' => '/api/virtuprof/dismiss', 'verb' => 'POST'],
        ['name' => 'virtuProf#setEnabled', 'url' => '/api/virtuprof/enabled', 'verb' => 'PUT'],
        ['name' => 'virtuProf#setLanguage', 'url' => '/api/virtuprof/language', 'verb' => 'PUT'],
        ['name' => 'virtuProf#chat', 'url' => '/api/virtu-prof/chat', 'verb' => 'POST'],

        // Support Tickets
        ['name' => 'supportTicket#create', 'url' => '/api/support-tickets', 'verb' => 'POST'],
        ['name' => 'supportTicket#mine', 'url' => '/api/support-tickets', 'verb' => 'GET'],
        ['name' => 'supportTicket#adminList', 'url' => '/api/settings/admin/support-tickets', 'verb' => 'GET'],
        ['name' => 'supportTicket#answer', 'url' => '/api/settings/admin/support-tickets/{id}/answer', 'verb' => 'POST'],

        // AI Generation
        ['name' => 'ai#available', 'url' => '/api/ai/available', 'verb' => 'GET'],
        ['name' => 'ai#generate', 'url' => '/api/ai/generate', 'verb' => 'POST'],
        ['name' => 'ai#status', 'url' => '/api/ai/status/{taskId}', 'verb' => 'GET'],
        ['name' => 'ai#import', 'url' => '/api/ai/import/{taskId}', 'verb' => 'POST'],
        ['name' => 'ai#explain', 'url' => '/api/ai/explain', 'verb' => 'POST'],

        // Duels
        ['name' => 'duel#create', 'url' => '/api/duels', 'verb' => 'POST'],
        ['name' => 'duel#invite', 'url' => '/api/duel-invites', 'verb' => 'POST'],
        ['name' => 'duel#invites', 'url' => '/api/duel-invites', 'verb' => 'GET'],
        ['name' => 'duel#acceptInvite', 'url' => '/api/duel-invites/{id}/accept', 'verb' => 'POST'],
        ['name' => 'duel#declineInvite', 'url' => '/api/duel-invites/{id}/decline', 'verb' => 'POST'],
        ['name' => 'duel#cancelInvite', 'url' => '/api/duel-invites/{id}/cancel', 'verb' => 'POST'],
        ['name' => 'duel#opponents', 'url' => '/api/courses/{courseId}/duel-opponents', 'verb' => 'GET'],
        ['name' => 'duel#join', 'url' => '/api/duels/{code}/join', 'verb' => 'POST'],
        ['name' => 'duel#ready', 'url' => '/api/duels/{code}/ready', 'verb' => 'POST'],
        ['name' => 'duel#state', 'url' => '/api/duels/{code}/state', 'verb' => 'GET'],
        ['name' => 'duel#answer', 'url' => '/api/duels/{code}/answer', 'verb' => 'POST'],
        ['name' => 'duel#rematch', 'url' => '/api/duels/{code}/rematch', 'verb' => 'POST'],

        // Leagues
        ['name' => 'league#create', 'url' => '/api/courses/{courseId}/leagues', 'verb' => 'POST'],
        ['name' => 'league#active', 'url' => '/api/courses/{courseId}/leagues/active', 'verb' => 'GET'],
        ['name' => 'league#start', 'url' => '/api/courses/{courseId}/leagues/{id}/start', 'verb' => 'POST'],
        ['name' => 'league#finish', 'url' => '/api/courses/{courseId}/leagues/{id}/finish', 'verb' => 'POST'],
        ['name' => 'league#challenge', 'url' => '/api/courses/{courseId}/leagues/{id}/challenge/{opponentUid}', 'verb' => 'POST'],
        ['name' => 'league#accept', 'url' => '/api/courses/{courseId}/leagues/{id}/challenges/{challengeId}/accept', 'verb' => 'POST'],
        ['name' => 'league#table', 'url' => '/api/courses/{courseId}/leagues/{id}/table', 'verb' => 'GET'],
        ['name' => 'league#cl', 'url' => '/api/courses/{courseId}/leagues/{id}/cl', 'verb' => 'GET'],

        // Course Management
        ['name' => 'course#role', 'url' => '/api/role', 'verb' => 'GET'],
        ['name' => 'course#index', 'url' => '/api/courses', 'verb' => 'GET'],
        ['name' => 'course#show', 'url' => '/api/courses/{courseId}', 'verb' => 'GET'],
        ['name' => 'course#create', 'url' => '/api/courses', 'verb' => 'POST'],
        ['name' => 'course#update', 'url' => '/api/courses/{courseId}', 'verb' => 'PUT'],
        ['name' => 'course#destroy', 'url' => '/api/courses/{courseId}', 'verb' => 'DELETE'],
        ['name' => 'course#listPools', 'url' => '/api/courses/{courseId}/pools', 'verb' => 'GET'],
        ['name' => 'course#addPool', 'url' => '/api/courses/{courseId}/pools', 'verb' => 'POST'],
        ['name' => 'course#updatePool', 'url' => '/api/courses/{courseId}/pools/{poolId}', 'verb' => 'PUT'],
        ['name' => 'course#removePool', 'url' => '/api/courses/{courseId}/pools/{poolId}', 'verb' => 'DELETE'],
        ['name' => 'course#listMembers', 'url' => '/api/courses/{courseId}/members', 'verb' => 'GET'],
        ['name' => 'course#addMember', 'url' => '/api/courses/{courseId}/members', 'verb' => 'POST'],
        ['name' => 'course#removeMember', 'url' => '/api/courses/{courseId}/members/{memberId}', 'verb' => 'DELETE'],
        ['name' => 'course#enroll', 'url' => '/api/courses/{courseId}/enroll', 'verb' => 'POST'],
        ['name' => 'course#progress', 'url' => '/api/courses/{courseId}/progress', 'verb' => 'GET'],
        ['name' => 'course#myProgress', 'url' => '/api/courses/{courseId}/my-progress', 'verb' => 'GET'],
        ['name' => 'course#leaderboard', 'url' => '/api/courses/{courseId}/leaderboard', 'verb' => 'GET'],
        ['name' => 'course#atRisk', 'url' => '/api/courses/{courseId}/at-risk', 'verb' => 'GET'],
        ['name' => 'course#exportAtRiskCsv', 'url' => '/api/courses/{courseId}/at-risk/export/csv', 'verb' => 'GET'],
        ['name' => 'course#studentDetail', 'url' => '/api/courses/{courseId}/students/{studentId}', 'verb' => 'GET'],
        ['name' => 'course#getCurriculumScope', 'url' => '/api/courses/{courseId}/curriculum-scope', 'verb' => 'GET'],
        ['name' => 'course#updateCurriculumScope', 'url' => '/api/courses/{courseId}/curriculum-scope', 'verb' => 'PUT'],
        ['name' => 'course#dashboard', 'url' => '/api/instructor/dashboard', 'verb' => 'GET'],
        ['name' => 'course#chapterHeatmap', 'url' => '/api/courses/{courseId}/chapter-heatmap', 'verb' => 'GET'],
        ['name' => 'course#weakQuestions', 'url' => '/api/courses/{courseId}/weak-questions', 'verb' => 'GET'],
        ['name' => 'course#setQuestionOverride', 'url' => '/api/courses/{courseId}/questions/{questionId}/override', 'verb' => 'POST'],
        ['name' => 'course#courseDashboard', 'url' => '/api/courses/{courseId}/dashboard', 'verb' => 'GET'],
        ['name' => 'course#getAnnouncements', 'url' => '/api/courses/{courseId}/announcements', 'verb' => 'GET'],
        ['name' => 'course#createAnnouncement', 'url' => '/api/courses/{courseId}/announcements', 'verb' => 'POST'],
        ['name' => 'course#deleteAnnouncement', 'url' => '/api/courses/{courseId}/announcements/{announcementId}', 'verb' => 'DELETE'],
        ['name' => 'course#getActiveExamSlot', 'url' => '/api/courses/{courseId}/exam-slot/active', 'verb' => 'GET'],
        ['name' => 'course#createExamSlot', 'url' => '/api/courses/{courseId}/exam-slot', 'verb' => 'POST'],
        ['name' => 'course#closeExamSlot', 'url' => '/api/courses/{courseId}/exam-slot/close', 'verb' => 'POST'],
        ['name' => 'supportTicket#instructorList', 'url' => '/api/courses/{courseId}/support-tickets', 'verb' => 'GET'],

        // Mode Config
        ['name' => 'course#updateModeConfig', 'url' => '/api/courses/{courseId}/mode-config', 'verb' => 'PUT'],

        // Gameshow
        ['name' => 'gameshow#create', 'url' => '/api/gameshow', 'verb' => 'POST'],
        ['name' => 'gameshow#history', 'url' => '/api/gameshow/history', 'verb' => 'GET'],
        ['name' => 'gameshow#join', 'url' => '/api/gameshow/{code}/join', 'verb' => 'POST'],
        ['name' => 'gameshow#ready', 'url' => '/api/gameshow/{code}/ready', 'verb' => 'POST'],
        ['name' => 'gameshow#state', 'url' => '/api/gameshow/{code}/state', 'verb' => 'GET'],
        ['name' => 'gameshow#answer', 'url' => '/api/gameshow/{code}/answer', 'verb' => 'POST'],
        ['name' => 'gameshow#courseLobby', 'url' => '/api/courses/{courseId}/gameshow/lobby', 'verb' => 'GET'],
    ]
];
