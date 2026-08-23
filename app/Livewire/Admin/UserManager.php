<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class UserManager extends Component
{
    use RequiresPermission, WithPagination;

    protected string $requiredPermission = 'users.manage';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255|unique:users,username')]
    public string $username = '';

    #[Validate('required|string|min:8')]
    public string $password = '';

    #[Validate('required|exists:roles,name')]
    public string $role = '';

    public bool $showCreateForm = false;

    /**
     * 編輯只動姓名／身分，帳號（username）維持不可改——它是登入識別碼，
     * RateLimiter 的節流 key、各種稽核紀錄都間接依賴這個值不會變動，
     * 改名字/改身分不需要跟著動它。密碼也不透過這個表單改，有專門的
     * 「重置密碼」流程（見下）。
     */
    public ?int $editingUserId = null;

    /**
     * 「重置密碼」是管理者幫別人（忘記密碼、剛建立帳號）代打一個新密碼
     * 用的，跟編輯姓名/身分是三選一互斥的操作狀態，理由跟其他 Manager
     * 的 create/edit 互斥問題一樣：共用畫面上同一列，同時開兩個表單會
     * 讓人搞不清楚在編輯哪一個。
     */
    public ?int $resettingPasswordUserId = null;

    public string $newPassword = '';

    /**
     * 每個 User 只能有一個角色，資料表的三種角色階級是彼此互斥的身份，
     * 不是可疊加的權限包。如果之後甲方需要一人多角色，這裡改成多選即可，
     * spatie 本身就支援 assignRole() 傳陣列。
     */
    public function createUser(): void
    {
        $this->validate();

        $user = User::create([
            'name' => $this->name,
            'username' => $this->username,
            'password' => Hash::make($this->password),
        ]);

        // 管理者代打的初始密碼，帳號本人不知道系統怎麼決定它、以後也不
        // 應該繼續用它——強制首次登入後就得換成只有自己知道的密碼。
        $user->forceFill(['must_change_password' => true])->save();

        $user->assignRole($this->role);

        $this->reset(['name', 'username', 'password', 'role', 'showCreateForm']);

        session()->flash('status', "帳號 {$user->username} 建立成功。");
    }

    public function toggleCreateForm(): void
    {
        if ($this->showCreateForm) {
            $this->showCreateForm = false;

            return;
        }

        $this->cancelEdit();
        $this->cancelResetPassword();
        $this->showCreateForm = true;
    }

    public function toggleActive(User $user): void
    {
        // 管理者不能不小心把自己停用，鎖在 UI 之外避免自我鎖死。
        if ($user->is($this->currentAdmin())) {
            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->invalidateSessions();
        }
    }

    /**
     * 不開放編輯自己的帳號——姓名還好，但身分（角色）欄位混在同一個
     * 表單裡，萬一手滑把自己的角色改掉會直接把自己鎖出後台。乾脆整個
     * 編輯功能都不對自己開放，需要改自己的姓名請別的管理者代勞。
     */
    public function startEdit(User $user): void
    {
        if ($user->is($this->currentAdmin())) {
            return;
        }

        $this->showCreateForm = false;
        $this->cancelResetPassword();

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->role = $user->roles->first()?->name ?? '';
    }

    public function updateUser(): void
    {
        // 故意用明確傳入的規則陣列而不是裸的 validate()：UserManager 的
        // #[Validate] 屬性標記在 username/password 上，裸 validate() 會
        // 連這兩個沒有出現在編輯表單裡的屬性也一併驗證，拿目前殘留的
        // 值（很可能是空字串）去驗 unique/required，直接誤判失敗。
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'exists:roles,name'],
        ]);

        $user = User::findOrFail($this->editingUserId);

        // 伺服器端再擋一次，理由跟 startEdit() 一樣——防止繞過畫面直接
        // 呼叫這個方法把自己的角色改掉。
        if ($user->is($this->currentAdmin())) {
            $this->cancelEdit();

            return;
        }

        $user->update(['name' => $this->name]);
        $user->syncRoles([$this->role]);

        $this->cancelEdit();

        session()->flash('status', "帳號 {$user->username} 已更新。");
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingUserId', 'name', 'role']);
    }

    /**
     * 重置別人忘記的密碼用——自己的密碼不透過這裡改，見下方「變更密碼」
     * 自助頁面（App\Livewire\Account\ChangePassword）。
     */
    public function startResetPassword(User $user): void
    {
        if ($user->is($this->currentAdmin())) {
            return;
        }

        $this->showCreateForm = false;
        $this->cancelEdit();

        $this->resettingPasswordUserId = $user->id;
        $this->newPassword = '';
    }

    public function resetPassword(): void
    {
        $this->validate(['newPassword' => ['required', 'string', 'min:8']]);

        $user = User::findOrFail($this->resettingPasswordUserId);

        if ($user->is($this->currentAdmin())) {
            $this->cancelResetPassword();

            return;
        }

        $user->forceFill([
            'password' => Hash::make($this->newPassword),
            'must_change_password' => true,
        ])->save();

        // 換掉密碼後，用舊密碼登入中的 session 不該繼續有效——跟
        // toggleActive() 停用帳號時的處理一致。
        $user->invalidateSessions();

        $this->cancelResetPassword();

        session()->flash('status', "帳號 {$user->username} 的密碼已重設，該帳號下次登入需要另外設定新密碼。");
    }

    public function cancelResetPassword(): void
    {
        $this->reset(['resettingPasswordUserId', 'newPassword']);
    }

    /**
     * 三道防線都擋住才真的刪除：不能刪自己（避免自我鎖死）、不能刪掉
     * 系統裡最後一個 admin（避免整個後台沒有人能管理）、有點名/處理
     * 情形操作紀錄的帳號不能刪（見 User::hasAttendanceHistory()，刪掉
     * 會讓稽核歷程出現斷點，也會撞上資料庫的外鍵限制噴 500）——這種
     * 帳號請改用「停用」。
     */
    public function deleteUser(User $user): void
    {
        if ($user->is($this->currentAdmin())) {
            return;
        }

        if ($user->hasRole('admin') && User::role('admin')->count() <= 1) {
            session()->flash('error', '這是系統裡最後一個管理者帳號，無法刪除。');

            return;
        }

        if ($user->hasAttendanceHistory()) {
            session()->flash('error', "帳號 {$user->username} 已有點名或處理情形的操作紀錄，為保留稽核歷程無法刪除，可以改用「停用」。");

            return;
        }

        $user->invalidateSessions();
        $user->delete();

        session()->flash('status', "帳號 {$user->username} 已刪除。");
    }

    protected function currentAdmin(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render()
    {
        return view('livewire.admin.user-manager', [
            'users' => User::with('roles')->orderBy('name')->paginate(15),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }
}
