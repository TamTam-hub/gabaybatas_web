<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_success([
    'message' => 'GabayBatas API v1 is running.',
    'version' => '1.0.0',
    'endpoints' => [
        [
            'method' => 'GET',
            'path' => '/api/v1/index.php',
            'description' => 'API health check and endpoint list',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/cases_submit.php',
            'description' => 'Submit a resident case from mobile app',
            'required_fields' => ['name', 'complained_person', 'barangay', 'contact', 'case_type', 'description'],
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/cases_track.php?case_id={value}',
            'description' => 'Track case status by case_id or id',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/knowledge_base.php',
            'description' => 'List legal knowledge base articles',
            'query_params' => ['category (optional)', 'search (optional)'],
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/analytics_overview.php',
            'description' => 'Get dashboard-ready case analytics summary',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/auth_register.php',
            'description' => 'Register a resident account',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/auth_login.php',
            'description' => 'Authenticate a resident account',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/profile.php',
            'description' => 'Fetch the current authenticated profile',
        ],
        [
            'method' => 'PUT',
            'path' => '/api/v1/profile.php',
            'description' => 'Update the current authenticated profile',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/cases_list.php',
            'description' => 'List cases for the current authenticated user',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/notifications.php',
            'description' => 'List notifications for the current authenticated user',
        ],
        [
            'method' => 'PATCH',
            'path' => '/api/v1/notifications.php',
            'description' => 'Mark a notification as read',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/chat_threads.php',
            'description' => 'List chat threads for the current authenticated user',
        ],
        [
            'method' => 'GET',
            'path' => '/api/v1/chat_thread_messages.php?thread_id={value}',
            'description' => 'List messages for a chat thread',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/chat_thread_send.php',
            'description' => 'Send a message into a chat thread',
        ],
    ],
]);
