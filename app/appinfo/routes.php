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
        ['name' => 'question#index', 'url' => '/api/pools/{poolId}/questions', 'verb' => 'GET'],
        ['name' => 'question#show', 'url' => '/api/questions/{id}', 'verb' => 'GET'],
        ['name' => 'question#create', 'url' => '/api/questions', 'verb' => 'POST'],
        ['name' => 'question#update', 'url' => '/api/questions/{id}', 'verb' => 'PUT'],
        ['name' => 'question#destroy', 'url' => '/api/questions/{id}', 'verb' => 'DELETE'],
        
        // Training
        ['name' => 'training#start', 'url' => '/api/training/start', 'verb' => 'POST'],
        ['name' => 'training#answer', 'url' => '/api/training/answer', 'verb' => 'POST'],
        ['name' => 'training#complete', 'url' => '/api/training/complete', 'verb' => 'POST'],
        
        // Leitner
        ['name' => 'leitner#initialize', 'url' => '/api/leitner/initialize', 'verb' => 'POST'],
        ['name' => 'leitner#due', 'url' => '/api/leitner/due', 'verb' => 'GET'],
        ['name' => 'leitner#answer', 'url' => '/api/leitner/answer', 'verb' => 'POST'],
        ['name' => 'leitner#stats', 'url' => '/api/leitner/stats', 'verb' => 'GET'],
    ]
];
