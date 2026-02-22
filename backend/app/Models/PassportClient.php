<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

class PassportClient extends Client
{
    /**
     * Skip the authorization prompt for first-party clients.
     * Since we only have our own CLI client, auto-approve all.
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
