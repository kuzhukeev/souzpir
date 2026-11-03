<?php

namespace Tests\Feature\Vision\Publications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationAccessE819BTest extends TenancyTestCase
{
    public function test_guest_cannot_publish_and_unknown_public_token_is_not_found(): void
    {
        $this->post('/vision/publications', ['report_id' => 'invalid', 'ttl_days' => -1])->assertRedirect();
        $this->get('/v/'.str_repeat('f', 64))->assertNotFound();
    }

    public function test_invalid_publication_input_is_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/publications', ['report_id' => 'invalid', 'ttl_days' => -1])->assertSessionHasErrors(['report_id', 'ttl_days']);
    }
}
