<?php

namespace App\Tenancy\Console;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OrgCreateCommand extends Command
{
    protected $signature = 'org:create {name} {owner_email} {--plan=}';

    protected $description = 'Создать организацию, первый бренд и приглашение владельца';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = mb_strtolower(trim((string) $this->argument('owner_email')));
        if ($name === '') {
            $this->error('Укажите название организации.');

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Укажите корректную почту владельца.');

            return self::FAILURE;
        }
        $planCode = (string) ($this->option('plan') ?: config('features.L1-07.defaults.default_plan'));
        $plan = DB::table('plans')->where('code', $planCode)->first(['id']);
        if (! $plan) {
            $this->error("Тариф «{$planCode}» не найден.");

            return self::FAILURE;
        }
        if (Organization::query()->where('name', $name)->exists()) {
            $this->error('Организация с таким названием уже существует.');

            return self::FAILURE;
        }
        $userId = DB::table('users')->where('email', $email)->value('id');
        $hasInvite = Invitation::withoutGlobalScopes()->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        $hasMembership = $userId && Membership::withoutGlobalScopes()->where('user_id', $userId)->where('status', 'active')->exists();
        if ($hasInvite || $hasMembership) {
            $this->error('Для этой почты уже есть активное приглашение или участие в организации.');

            return self::FAILURE;
        }

        $secret = Str::random(64);
        try {
            $link = DB::transaction(function () use ($name, $email, $plan, $secret): string {
                $organization = Organization::create(['name' => $name, 'status' => 'active']);
                $context = new TenantContext($organization->id);
                $invitation = app(TenantContextRunner::class)->run($context, function () use ($name, $email, $secret): Invitation {
                    Brand::create(['name' => $name]);

                    return Invitation::create([
                        'email' => $email, 'role' => 'admin', 'brand_ids' => [],
                        'settings' => ['designated_owner' => true],
                        'token_hash' => hash('sha256', $secret), 'invited_by' => null,
                        'expires_at' => now()->addDays(7),
                    ]);
                });
                abort_unless($invitation->organization_id === $organization->id, 500);
                DB::table('organization_plan')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'plan_id' => $plan->id,
                    'starts_at' => now(), 'ends_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return rtrim((string) config('app.url'), '/').route('invitation.open', absolute: false).'#'.$secret;
            });
        } catch (Throwable $error) {
            $this->error('Не удалось создать организацию: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            unset($secret);
        }

        $this->info('Организация создана.');
        $this->line('Ссылка для владельца (действует 7 дней, открывается один раз):');
        $this->line($link);

        return self::SUCCESS;
    }
}
