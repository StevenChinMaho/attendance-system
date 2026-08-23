<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserHasChangedPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_who_must_change_password_is_redirected_away_from_other_pages(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('account.password'));
    }

    public function test_a_user_who_must_change_password_can_still_reach_the_change_password_page(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/account/password')
            ->assertOk();
    }

    public function test_a_user_who_must_change_password_can_still_log_out(): void
    {
        // 沒有這個排除，會被卡在「一直被導去改密碼頁面」的迴圈裡，連
        // 登出都做不到。
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');
    }

    public function test_a_user_who_does_not_need_to_change_password_is_not_redirected(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }
}
