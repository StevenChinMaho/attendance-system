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

        $user->assignRole($this->role);

        $this->reset(['name', 'username', 'password', 'role', 'showCreateForm']);

        session()->flash('status', "帳號 {$user->username} 建立成功。");
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
