<?php

use App\Shell\Auth\SessionRegistered;
use App\Shell\Profile\DispatchEmailChanges;
use App\Shell\Profile\EmailChangeSender;
use App\Shell\Profile\MailEmailChangeSender;
use App\Shell\Profile\ProfileContext;
use App\Shell\Profile\Security\CaptureSessionDetails;

return [
    'middleware_aliases' => ['profile.context' => ProfileContext::class],
    'commands' => [DispatchEmailChanges::class],
    'bindings' => [EmailChangeSender::class => MailEmailChangeSender::class],
    'listeners' => [SessionRegistered::class => [CaptureSessionDetails::class]],
    'defaults' => [
        'email_change_enabled' => (bool) env('PROFILE_EMAIL_CHANGE_ENABLED', false),
        'telegram_bot_username' => env('PROFILE_TELEGRAM_BOT_USERNAME'),
        'telegram_webhook_secret' => env('PROFILE_TELEGRAM_WEBHOOK_SECRET'),
    ],
];
