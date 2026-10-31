<?php

use App\Shell\Support\Contracts\ProviderCooldowns;
use App\Shell\Support\Models\Ticket;
use App\Shell\Support\TicketPolicy;
use App\Shell\Support\UnavailableProviderCooldowns;

return [
    'bindings' => [ProviderCooldowns::class => UnavailableProviderCooldowns::class],
    'policies' => [Ticket::class => TicketPolicy::class],
    'defaults' => [
        'mail_enabled' => (bool) env('SUPPORT_MAIL_ENABLED', false),
        'smtp_enabled' => (bool) env('SUPPORT_SMTP_ENABLED', false),
        'mailer' => env('SUPPORT_MAILER', 'smtp'),
        'recipient' => env('SUPPORT_RECIPIENT'),
        'max_files' => 5,
        'max_file_kb' => 5120,
        'knowledge_base_url' => env('SUPPORT_KNOWLEDGE_BASE_URL'),
        'section_urls' => [],
        'articles' => [],
    ],
];
