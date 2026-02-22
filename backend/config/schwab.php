<?php

return [
    'client_id' => env('SCHWAB_CLIENT_ID'),
    'client_secret' => env('SCHWAB_CLIENT_SECRET'),
    'redirect_uri' => env('SCHWAB_REDIRECT_URI'),
    'authorize_url' => 'https://api.schwabapi.com/v1/oauth/authorize',
    'token_url' => 'https://api.schwabapi.com/v1/oauth/token',
    'api_base_url' => 'https://api.schwabapi.com/trader/v1',
];
