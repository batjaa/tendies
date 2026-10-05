<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LinkSessionService
{
    public function create(User $user): string
    {
        $id = Str::uuid()->toString();
        $data = ['user_id' => $user->id, 'provider' => 'schwab'];
        Cache::put("link_session:{$id}", $data, now()->addMinutes(10));
        Cache::put("link_result:{$id}", $data + ['status' => 'pending'], now()->addMinutes(10));

        return $id;
    }

    public function consume(string $id): ?User
    {
        $data = Cache::pull("link_session:{$id}");
        if (! $data) {
            return null;
        }
        // Older CLI versions may have started this link before deployment.
        Cache::add("link_result:{$id}", $data + ['status' => 'pending'], now()->addMinutes(10));

        return User::find($data['user_id']);
    }

    public function finish(?string $id, bool $successful): void
    {
        if ($id && ($result = Cache::get("link_result:{$id}")) && $result['status'] === 'pending') {
            $result['status'] = $successful ? 'linked' : 'failed';
            Cache::put("link_result:{$id}", $result, now()->addMinutes(10));
        }
    }
}
