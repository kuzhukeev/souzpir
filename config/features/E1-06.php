<?php

return [
    'defaults' => [
        'mail_enabled' => (bool) env('TEAM_INVITATIONS_MAIL_ENABLED', false),
        'smtp_enabled' => (bool) env('TEAM_INVITATIONS_SMTP_ENABLED', false),
        'mailer' => env('TEAM_INVITATIONS_MAILER', 'smtp'),
    ],
];
