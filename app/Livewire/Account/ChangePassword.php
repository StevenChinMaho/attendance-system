<?php

namespace App\Livewire\Account;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * 帳號自己變更密碼的地方——管理者用 UserManager::resetPassword() 幫別人
 * 代打新密碼是「別人忘記密碼時的救急手段」，不是同一條路；這裡才是
 * 帳號本人真正能自主決定新密碼的地方，也是「強制首次登入改密碼」機制
 * 實際生效的頁面（見 App\Http\Middleware\EnsureUserHasChangedPassword，
 * must_change_password 為 true 時會被導來這裡）。
 *
 * 要求輸入目前密碼才能改新密碼——即使已經是通過 auth middleware 的
 * 已登入 session，還是要再驗證一次目前密碼，避免瀏覽器沒關、離開座位
 * 被別人接手操作時，密碼被人在不知道原密碼的情況下就直接改掉、鎖住
 * 真正的帳號主人。
 */
class ChangePassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = Auth::user();

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', '目前密碼不正確。');

            return;
        }

        // 故意不呼叫 invalidateSessions()：那個方法連目前這個請求所在的
        // session 一起刪掉，會讓使用者剛設定好新密碼就被登出，尤其是
        // 「強制首次登入改密碼」這個情境下體驗會很糟——跟 UserManager::
        // resetPassword() 不同，這裡是帳號本人自己動手，不是別人代為
        // 重置，不需要把自己現有的登入狀態也一起清掉。
        $user->forceFill([
            'password' => Hash::make($this->newPassword),
            'must_change_password' => false,
        ])->save();

        $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);

        session()->flash('status', '密碼已更新。');
    }

    public function render()
    {
        return view('livewire.account.change-password');
    }
}
