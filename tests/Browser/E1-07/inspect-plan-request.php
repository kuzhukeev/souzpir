<?php

use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$ticketId = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$orgId = getenv('E107_FIXTURE_ORG');
if (getenv('E107_FIXTURE_ENABLED') !== '1' || ! app()->environment('testing') || realpath(base_path()) !== realpath(__DIR__.'/../../..') || basename(dirname(base_path())) !== '.worktrees' || basename(base_path()) !== 'sp-e1-07' || config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55607' || DB::connection()->getDatabaseName() !== 'souzpir_test_sp_e1_07' || config('runtime.test_database') !== 'souzpir_test_sp_e1_07' || config('runtime.preview_database') === 'souzpir_test_sp_e1_07' || ! Str::isUlid($ticketId) || ! is_string($orgId) || ! Str::isUlid($orgId) || ! in_array($mode, ['inspect', 'process'], true)) {
    throw new LogicException('Requires the explicitly enabled isolated fixture and test database.');
}
$connection = DB::selectOne('select current_database() as db, current_user as role, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
if ($connection->db !== 'souzpir_test_sp_e1_07' || $connection->role !== 'souzpir_sp_e1_07' || $connection->rolsuper || $connection->rolcreatedb || $connection->rolcreaterole || $connection->rolreplication || $connection->rolbypassrls || config('features.E1-12.defaults.mail_enabled') !== false || config('features.E1-12.defaults.smtp_enabled') !== false || config('features.E1-12.defaults.recipient') !== null || config('features.E0-04.defaults.external_delivery') !== false || config('mail.default') !== 'log') {
    throw new LogicException('Unsafe database role or delivery configuration.');
}
$user = DB::table('users')->where('email', 'billing-e107-owner@example.test')->sole();
$ticket = DB::table('tickets')->where('id', $ticketId)->where('organization_id', $orgId)->where('user_id', $user->id)->where('type', 'plan_request')->sole();
$event = DB::table('outbox_events')->where('id', $ticketId)->where('organization_id', $orgId)->sole();
$envelope = json_decode($event->envelope, true, flags: JSON_THROW_ON_ERROR);
if (($envelope['type'] ?? null) !== 'shell.plan_request.created' || ($envelope['data']['ticket_id'] ?? null) !== $ticketId || ! str_contains($ticket->body, '01J00000000000000000000007')) {
    throw new LogicException('Unexpected fixture ticket or event.');
}
if ($mode === 'process') {
    // This uses the actual outbox consumer; external transports were rejected above.
    app(NotificationProcessor::class)->process($ticketId);
    app(NotificationProcessor::class)->process($ticketId);
}
$deliveries = DB::table('notification_deliveries')->where('event_id', $ticketId);
$result = [
    'ticket_id' => $ticketId,
    'ticket_count' => DB::table('tickets')->where('organization_id', $orgId)->where('user_id', $user->id)->where('submission_key', $ticket->submission_key)->count(),
    'delivery_status' => $ticket->delivery_status,
    'outbox_count' => DB::table('outbox_events')->where('id', $ticketId)->count(),
    'outbox_processed' => DB::table('outbox_events')->where('id', $ticketId)->whereNotNull('processed_at')->exists(),
    'audit_count' => DB::table('audit_logs')->where('subject_id', $ticketId)->where('action', 'billing.plan_request.created')->count(),
    'cabinet_delivery_count' => (clone $deliveries)->where('channel', 'cabinet')->where('status', 'sent')->where('user_id', $user->id)->count(),
    'external_delivery_count' => (clone $deliveries)->whereIn('channel', ['email', 'telegram'])->count(),
    'cabinet_notification_count' => DB::table('cabinet_notifications')->join('notification_deliveries', 'cabinet_notifications.delivery_id', '=', 'notification_deliveries.id')->where('notification_deliveries.event_id', $ticketId)->where('cabinet_notifications.user_id', $user->id)->where('cabinet_notifications.event_key', 'shell.plan_request.created')->count(),
];
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
