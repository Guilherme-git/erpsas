<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Filament\Actions\Imports\Models\Import as BaseImport;

class Import extends BaseImport
{
    use CompanyOwned;
}
