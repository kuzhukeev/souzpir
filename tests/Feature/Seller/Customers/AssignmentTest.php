<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssignmentTest extends TenancyTestCase
{
    private function fixture(string $org, string $brand): array
    {
        $contact = (string) Str::ulid();
        $dialog = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org, 'brand_id' => $brand, 'name' => 'Клиент', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org, 'brand_id' => $brand, 'contact_id' => $contact, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);

        return [$contact, $dialog];
    }

    public function test_assignment_dialog_link_permissions_and_brand(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$foreignManager] = $this->member($org, 'member', [$other->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$contact,$dialog] = $this->fixture($org->id, $brand->id);
        [$foreign] = $this->fixture($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post("/seller/customers/$contact/manager", ['manager_id' => $manager->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialog, 'assigned_user_id' => $manager->id]);
        $this->get("/seller/customers/$contact")->assertOk()->assertInertia(fn ($page) => $page->where('assignedUserId', $manager->id)->where('conversations.0.id', $dialog)->where('canReadDialogs', true));
        $this->post("/seller/customers/$contact/manager", ['manager_id' => $foreignManager->id])->assertSessionHasErrors('manager_id');
        $this->post("/seller/customers/$contact/manager", ['manager_id' => 'invalid'])->assertSessionHasErrors('manager_id');
        $this->post("/seller/customers/$foreign/manager", ['manager_id' => $manager->id])->assertNotFound();
        $this->get("/seller/customers/$foreign")->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post("/seller/customers/$contact/manager", ['manager_id' => $viewer->id])->assertForbidden();
    }
}
