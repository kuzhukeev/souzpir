<?php

return [
    'instance' => env('APP_INSTANCE', 'souzpir-main'),
    'preview_database' => env('APP_PREVIEW_DATABASE', env('DB_DATABASE', 'souzpir_dev')),
    'test_database' => env('DB_TEST_DATABASE'),
    'queue_namespace' => env('QUEUE_NAMESPACE', env('APP_INSTANCE', 'souzpir-main')),
    'queues' => array_combine(
        ['seller-turns', 'vision', 'ai', 'notifications', 'default'],
        array_map(fn ($name) => env('QUEUE_NAMESPACE', env('APP_INSTANCE', 'souzpir-main')).':'.$name, ['seller-turns', 'vision', 'ai', 'notifications', 'default']),
    ),
    'ai_driver' => env('AI_DRIVER', 'fake'),
    'delivery_driver' => env('DELIVERY_DRIVER', 'fake'),
    'notification_driver' => env('NOTIFICATION_DRIVER'),
    'features_scheduling_enabled' => (bool) env('FEATURES_SCHEDULING_ENABLED', false),
];
