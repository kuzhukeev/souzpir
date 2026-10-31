<?php

namespace Tests\Feature\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Support\Models\SupportAccessGrant;
use App\Shell\Support\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class SupportBoundaryTest extends SupportTestCase
{
    public function test_same_user_in_another_organization_cannot_cross_ticket_or_attachment_boundary(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $local = $this->ticket()['ticket'];
        $foreign = Organization::create(['name' => 'Foreign cabinet']);
        $foreignBrand = app(TenantContextRunner::class)->run(new TenantContext($foreign->id, source: 'job'), function () use ($owner): Brand {
            $brand = Brand::create(['name' => 'Foreign', 'theme' => 'neutral']);
            Membership::create(['user_id' => $owner->id, 'role' => 'member', 'brand_ids' => [$brand->id]]);

            return $brand;
        });
        config(['features.E0-02.defaults.organization_id' => $foreign->id]);
        $this->signIn($owner, $foreignBrand);
        $hidden = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        self::assertSame($owner->id, Ticket::withoutGlobalScopes()->findOrFail($hidden['id'])->user_id);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $this->signIn($owner, $brand);
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.tickets.total', 1)->assertJsonPath('data.tickets.data.0.id', $local['id']);
        $this->get($hidden['attachments'][0]['download_url'])->assertNotFound();
        $this->postJson('/help/support/'.$hidden['id'].'/access/revoke')->assertNotFound();
        self::assertNull(SupportAccessGrant::withoutGlobalScopes()->findOrFail($hidden['access']['id'])->revoked_at);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'support.access.revoked')->count());
    }

    public function test_actual_svg_bytes_disguised_as_text_are_rejected_before_storage(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $directory = storage_path('framework/testing/support-upload-fixtures');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'svg-');
        try {
            file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
            $file = new UploadedFile($path, 'disguised.txt', null, null, true);
            self::assertSame('image/svg+xml', $file->getMimeType());
            $response = $this->post('/help/support', [...$this->payload(), 'attachments' => [$file]], ['Accept' => 'application/json'])->assertUnprocessable();
            self::assertSame('Допустимы файлы JPEG, PNG, WebP, PDF и TXT.', $response->json('errors')['attachments.0'][0]);
            self::assertSame(0, Ticket::withoutGlobalScopes()->count());
            self::assertSame([], Storage::disk('local')->allFiles());
        } finally {
            File::delete($path);
        }
    }

    public function test_downgraded_viewer_can_read_and_revoke_own_consent(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$actor, $member] = $this->member($org, 'member', [$brand->id]);
        $this->signIn($actor, $brand);
        $ticket = $this->ticket(['attachments' => [$this->attachment()]])['ticket'];
        DB::table('memberships')->where('id', $member->id)->update(['role' => 'viewer']);
        $this->signIn($actor, $brand);
        $this->getJson('/help/support')->assertOk()->assertJsonPath('data.can_submit', true)->assertJsonPath('data.tickets.data.0.access.can_revoke', true);
        $this->get($ticket['attachments'][0]['download_url'])->assertOk();
        $this->postJson('/help/support/'.$ticket['id'].'/access/revoke')->assertOk();
        self::assertNotNull(SupportAccessGrant::withoutGlobalScopes()->findOrFail($ticket['access']['id'])->revoked_at);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'support.access.revoked')->count());
    }
}
