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
        ['name' => 'pool#destroy', 'url' => '/api/pools/{id}', 'verb' => 'DELETE'],

        // Questions
        ["name" => "question#search", "url" => "/api/questions/search", "verb" => "GET"],
        ['name' => 'question#index', 'url' => '/api/pools/{poolId}/questions', 'verb' => 'GET'],
        ['name' => 'question#show', 'url' => '/api/questions/{id}', 'verb' => 'GET'],
        ['name' => 'question#create', 'url' => '/api/questions', 'verb' => 'POST'],
        ['name' => 'question#update', 'url' => '/api/questions/{id}', 'verb' => 'PUT'],
        ['name' => 'question#destroy', 'url' => '/api/questions/{id}', 'verb' => 'DELETE'],

        // Training
        ['name' => 'training#start', 'url' => '/api/training/start', 'verb' => 'POST'],
        ['name' => 'training#answer', 'url' => '/api/training/answer', 'verb' => 'POST'],
        ['name' => 'training#submitBatch', 'url' => '/api/training/submitBatch', 'verb' => 'POST'],
        ['name' => 'training#complete', 'url' => '/api/training/complete', 'verb' => 'POST'],

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
        ['name' => 'translation#setQuestionTranslation', 'url' => '/api/questions/{questionId}/translations/{lang}', 'verb' => 'PUT'],
        ['name' => 'translation#deleteQuestionTranslation', 'url' => '/api/questions/{questionId}/translations/{lang}', 'verb' => 'DELETE'],
        ['name' => 'translation#answerTranslations', 'url' => '/api/answers/{answerId}/translations', 'verb' => 'GET'],
        ['name' => 'translation#setAnswerTranslation', 'url' => '/api/answers/{answerId}/translations/{lang}', 'verb' => 'PUT'],
        ['name' => 'translation#deleteAnswerTranslation', 'url' => '/api/answers/{answerId}/translations/{lang}', 'verb' => 'DELETE'],

        // Import
        ['name' => 'import#importCsv', 'url' => '/api/pools/{poolId}/import/csv', 'verb' => 'POST'],
        ['name' => 'import#importJson', 'url' => '/api/pools/{poolId}/import/json', 'verb' => 'POST'],

        // User State (consolidated endpoint)
        ['name' => 'user_state#state', 'url' => '/api/v1/user/state', 'verb' => 'GET'],
        ['name' => 'user_state#updateSettings', 'url' => '/api/v1/user/settings', 'verb' => 'PUT'],
        ['name' => 'user_state#dailyChallenge', 'url' => '/api/v1/daily-challenge', 'verb' => 'GET'],
        ['name' => 'user_state#answerChallenge', 'url' => '/api/v1/daily-challenge/answer', 'verb' => 'POST'],

        // AI Generation
        ['name' => 'ai#available', 'url' => '/api/ai/available', 'verb' => 'GET'],
        ['name' => 'ai#generate', 'url' => '/api/ai/generate', 'verb' => 'POST'],
        ['name' => 'ai#status', 'url' => '/api/ai/status/{taskId}', 'verb' => 'GET'],
        ['name' => 'ai#import', 'url' => '/api/ai/import/{taskId}', 'verb' => 'POST'],

        // Course Management
        ['name' => 'course#role', 'url' => '/api/role', 'verb' => 'GET'],
        ['name' => 'course#index', 'url' => '/api/courses', 'verb' => 'GET'],
        ['name' => 'course#show', 'url' => '/api/courses/{courseId}', 'verb' => 'GET'],
        ['name' => 'course#create', 'url' => '/api/courses', 'verb' => 'POST'],
        ['name' => 'course#update', 'url' => '/api/courses/{courseId}', 'verb' => 'PUT'],
        ['name' => 'course#destroy', 'url' => '/api/courses/{courseId}', 'verb' => 'DELETE'],
        ['name' => 'course#listPools', 'url' => '/api/courses/{courseId}/pools', 'verb' => 'GET'],
        ['name' => 'course#addPool', 'url' => '/api/courses/{courseId}/pools', 'verb' => 'POST'],
        ['name' => 'course#removePool', 'url' => '/api/courses/{courseId}/pools/{poolId}', 'verb' => 'DELETE'],
        ['name' => 'course#listMembers', 'url' => '/api/courses/{courseId}/members', 'verb' => 'GET'],
        ['name' => 'course#addMember', 'url' => '/api/courses/{courseId}/members', 'verb' => 'POST'],
        ['name' => 'course#removeMember', 'url' => '/api/courses/{courseId}/members/{memberId}', 'verb' => 'DELETE'],
        ['name' => 'course#enroll', 'url' => '/api/courses/{courseId}/enroll', 'verb' => 'POST'],
        ['name' => 'course#progress', 'url' => '/api/courses/{courseId}/progress', 'verb' => 'GET'],
        ['name' => 'course#leaderboard', 'url' => '/api/courses/{courseId}/leaderboard', 'verb' => 'GET'],
        ['name' => 'course#atRisk', 'url' => '/api/courses/{courseId}/at-risk', 'verb' => 'GET'],
        ['name' => 'course#studentDetail', 'url' => '/api/courses/{courseId}/students/{studentId}', 'verb' => 'GET'],
        ['name' => 'course#dashboard', 'url' => '/api/instructor/dashboard', 'verb' => 'GET'],
    ]
];
