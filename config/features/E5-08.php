<?php

use App\Seller\Customers\ImportExport\CustomersExport;

return ['extensions' => env('E5_08_EXPORT_ENABLED', false) ? ['export.seller' => CustomersExport::class] : []];
