<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Filament\Actions\Exports\Models\Export as BaseExport;

class Export extends BaseExport
{
    use CompanyOwned;
}
