<?php

namespace App\Models;

use App\Concerns\CompanyOwned;
use Illuminate\Notifications\DatabaseNotification;

class Notification extends DatabaseNotification
{
    use CompanyOwned;
}
