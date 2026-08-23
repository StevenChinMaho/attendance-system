<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 管理者透過這個頁面新增自訂角色、勾選要開放哪些頁面權限給它，再到
 * 帳號管理（UserManager）把角色指派給帳號——UserManager 的角色下拉選單
 * 是直接查 roles table（見 UserManager::render()），這裡新增的角色會
 * 自動出現在那邊，不需要另外改 UserManager。
 */
class RoleManager extends Component
{
    use RequiresPermission;

    protected string $requiredPermission = 'roles.manage';

    /**
     * 這三個角色是系統一開始就假設存在、程式碼裡多處直接寫死角色名稱
     * 依賴（例如 SchoolClassPolicy/AttendanceRecordPolicy 對 admin 的
     * 特殊放行、nav bar 對 student_rep/homeroom_teacher 的顯示邏輯），
     * 刪除或改名會讓那些地方失效，因此鎖住不能刪除；admin 的權限清單
     * 額外鎖住不能調整，避免管理者不小心把自己踢出後台、鎖死整個系統
     * （這三個角色以外新建的自訂角色，權限可以自由勾選/取消）。
     */
    private const PROTECTED_ROLE_NAMES = ['admin', 'homeroom_teacher', 'student_rep'];

    public const PERMISSION_LABELS = [
        'attendance.record' => '點名',
        'attendance.follow_up.manage' => '處理情形管理',
        'attendance.dashboard.view' => '即時看板',
        'users.manage' => '帳號管理',
        'teachers.manage' => '教師管理',
        'classes.manage' => '班級管理',
        'students.manage' => '學生管理',
        'roles.manage' => '角色管理',
    ];

    public string $name = '';

    /** @var array<int, string> */
    public array $selectedPermissions = [];

    public bool $showCreateForm = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;

        if ($this->showCreateForm) {
            $this->reset(['name', 'selectedPermissions']);
        }
    }

    public function createRole(): void
    {
        $this->validate();

        $role = Role::create(['name' => $this->name, 'guard_name' => 'web']);
        $role->syncPermissions($this->selectedPermissions);

        $this->reset(['name', 'selectedPermissions', 'showCreateForm']);

        session()->flash('status', "角色「{$role->name}」建立成功。");
    }

    /**
     * 逐一勾選/取消勾選既有角色的權限——每個 checkbox 都是獨立呼叫，
     * 不像 create 表單一次送出一批，避免跟其他管理元件一樣共用屬性
     * 造成的「同時開兩個表單互相污染」問題（見 CLAUDE.md）：這裡根本
     * 沒有共用屬性可以污染。
     */
    public function togglePermission(int $roleId, string $permission): void
    {
        $role = Role::findOrFail($roleId);

        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)) {
            abort(403, '系統內建角色的權限無法調整。');
        }

        if ($role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        } else {
            $role->givePermissionTo($permission);
        }
    }

    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)) {
            abort(403, '系統內建角色無法刪除。');
        }

        if ($role->users()->exists()) {
            session()->flash('error', "角色「{$role->name}」目前仍有帳號使用中，無法刪除。");

            return;
        }

        $role->delete();

        session()->flash('status', "角色「{$role->name}」已刪除。");
    }

    public function render()
    {
        return view('livewire.admin.role-manager', [
            'roles' => Role::with('permissions')->orderBy('name')->get(),
            'permissions' => Permission::orderBy('name')->get(),
            'permissionLabels' => self::PERMISSION_LABELS,
            'protectedRoleNames' => self::PROTECTED_ROLE_NAMES,
        ]);
    }
}
