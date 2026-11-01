<?php

use App\Migration\Console\ImportOrganizationCopy;
use App\Migration\Console\InspectOrganization;
use App\Migration\Console\RollbackOrganizationCopy;
use App\Migration\Console\ValidatePreparedOrganization;

return ['commands' => [InspectOrganization::class, ValidatePreparedOrganization::class, ImportOrganizationCopy::class, RollbackOrganizationCopy::class]];
