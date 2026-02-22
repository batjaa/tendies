<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize {{ $client->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .card { background: white; border-radius: 8px; padding: 2rem; max-width: 400px; width: 100%; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; }
        .scopes { margin: 1rem 0; padding: 0; list-style: none; }
        .scopes li { padding: 0.25rem 0; }
        .buttons { display: flex; gap: 0.5rem; margin-top: 1.5rem; }
        button { flex: 1; padding: 0.75rem; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        .approve { background: #22c55e; color: white; }
        .deny { background: #ef4444; color: white; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Authorization Request</h2>
        <p><strong>{{ $client->name }}</strong> is requesting access to your account.</p>

        @if (count($scopes) > 0)
            <ul class="scopes">
                @foreach ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @endforeach
            </ul>
        @endif

        <div class="buttons">
            <form method="POST" action="{{ route('passport.authorizations.approve') }}" style="flex:1">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve" style="width:100%">Authorize</button>
            </form>
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" style="flex:1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny" style="width:100%">Deny</button>
            </form>
        </div>
    </div>
</body>
</html>
