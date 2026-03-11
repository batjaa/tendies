<?php

namespace Tests\Traits;

use App\Models\PassportClient;

trait CreatesPersonalAccessClient
{
    protected function createPersonalAccessClient(): void
    {
        PassportClient::create([
            'name' => 'Test Personal Access Client',
            'secret' => null,
            'redirect_uris' => [],
            'grant_types' => ['personal_access'],
            'provider' => 'users',
            'revoked' => false,
        ]);
    }
}
