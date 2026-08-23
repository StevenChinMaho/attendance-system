<?php

namespace Tests\Feature\Account;

use App\Livewire\Account\ChangePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_away_from_the_change_password_page(): void
    {
        $this->get('/account/password')->assertRedirect('/');
    }

    public function test_a_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('old-password'),
            'must_change_password' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'old-password')
            ->set('newPassword', 'a-brand-new-password')
            ->set('newPassword_confirmation', 'a-brand-new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'a-brand-new-password')
            ->set('newPassword_confirmation', 'a-brand-new-password')
            ->call('updatePassword')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'old-password')
            ->set('newPassword', 'a-brand-new-password')
            ->set('newPassword_confirmation', 'does-not-match')
            ->call('updatePassword')
            ->assertHasErrors('newPassword');
    }

    public function test_the_new_password_must_be_at_least_eight_characters(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'old-password')
            ->set('newPassword', 'short')
            ->set('newPassword_confirmation', 'short')
            ->call('updatePassword')
            ->assertHasErrors('newPassword');
    }

    public function test_changing_your_own_password_does_not_log_you_out(): void
    {
        // 跟 UserManager::resetPassword()（別人幫你代打新密碼）不同，這裡
        // 是本人自己操作，不應該連自己目前這個 session 也一起清掉。
        $user = User::factory()->create(['password' => bcrypt('old-password')]);

        $component = Livewire::actingAs($user)
            ->test(ChangePassword::class)
            ->set('currentPassword', 'old-password')
            ->set('newPassword', 'a-brand-new-password')
            ->set('newPassword_confirmation', 'a-brand-new-password')
            ->call('updatePassword');

        $this->assertAuthenticatedAs($user);
    }
}
