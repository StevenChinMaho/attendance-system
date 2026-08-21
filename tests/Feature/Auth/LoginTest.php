<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_only_ever_sees_the_login_page(): void
    {
        $this->get('/')->assertOk()->assertSee('登入');
    }

    public function test_guest_is_redirected_away_from_protected_routes(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'teststudent',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post('/login', [
            'username' => 'teststudent',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_disabled_account_cannot_login_even_with_correct_password(): void
    {
        User::factory()->create([
            'username' => 'disabled',
            'password' => bcrypt('correct-password'),
            'is_active' => false,
        ]);

        $this->post('/login', [
            'username' => 'disabled',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
