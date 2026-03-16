<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_requires_auth(): void
    {
        $response = $this->get('/account');

        $response->assertRedirect('/login');
    }

    public function test_account_shows_user_info(): void
    {
        $user = User::factory()->onTrial()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
        $response->assertSee('Jane');
        $response->assertSee('jane@example.com');
    }

    public function test_account_shows_trading_accounts(): void
    {
        $user = User::factory()->onTrial()->create();
        $account = TradingAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'schwab',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($user)->get('/account');

        $response->assertStatus(200);
        $response->assertSee('schwab', false);
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $response = $this->actingAs($user)->post('/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    public function test_password_change_succeeds(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $response = $this->actingAs($user)->post('/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password', $user->fresh()->password));
    }

    public function test_disconnect_brokerage(): void
    {
        $user = User::factory()->onTrial()->create();
        $account = TradingAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'schwab',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($user)->delete("/account/brokerage/{$account->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('trading_accounts', ['id' => $account->id]);
    }

    public function test_disconnect_brokerage_forbidden_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = TradingAccount::factory()->create([
            'user_id' => $owner->id,
            'provider' => 'schwab',
        ]);

        $response = $this->actingAs($other)->delete("/account/brokerage/{$account->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('trading_accounts', ['id' => $account->id]);
    }

    public function test_logout_clears_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }
}
