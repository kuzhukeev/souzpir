<?php

namespace App\Seller\Customers\ImportExport;

use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class CustomersExport implements ExportContributor
{
    public function module(): string
    {
        return 'seller';
    }

    public function stream(TenantContext $tenant, ExportQuery $query): iterable
    {
        if (! $tenant->organizationId || ! $tenant->brandId) {
            return;
        }
        $contacts = DB::table('contacts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereNull('merged_into_id')->where('created_at', '>=', $query->from)->where('created_at', '<', $query->until)
            ->orderBy('id')->cursor();
        foreach ($contacts as $contact) {
            yield new ExportRecord('contact', ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone,
                'email' => $contact->email, 'created_at' => $contact->created_at]);
        }
    }
}
