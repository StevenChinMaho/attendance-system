<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresPermission;
use App\Support\AuditLog;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 管理者透過這個頁面新增自訂角色、勾選要開放哪些頁面權限給它，再到
 * 帳號管理（UserManager）把角色指派給帳號——UserManager 的角色下拉選單
 * 是直接查 roles table（見 UserManager::render()），這裡新增的角色會
 * 自動出現在那邊，不需要另外改 UserManager。
 *
 * 「角色」是資料庫/程式碼裡的用語（spatie 的 roles table），介面上
 * 一律顯示成「身分」——對非技術背景的管理者來說比較直觀，也避免跟
 * 這個頁面裡另一個核心概念「權限」（頁面級的 permission）搞混。內建
 * 三個角色的英文代號一律透過 App\Support\RoleLabel 轉成中文顯示；
 * 自訂角色沒有這層轉換，建立時就直接輸入中文名稱即可（見建立表單的
 * 說明文字）。
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
        'attendance.record.anytime' => '不限時段點名',
        'attendance.record.all' => '點名所有班級',
        'attendance.follow_up.manage' => '處理情形管理',
        'attendance.dashboard.view' => '即時看板',
        'users.manage' => '帳號管理',
        'teachers.manage' => '教師管理',
        'classes.manage' => '班級管理',
        'students.manage' => '學生管理',
        'roles.manage' => '身分管理',
        'audit.view' => '稽核紀錄',
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

        AuditLog::admin('建立身分', [
            'role_id' => $role->id,
            'role' => $role->name,
            'permissions' => array_values($this->selectedPermissions),
        ], $role);

        $this->reset(['name', 'selectedPermissions', 'showCreateForm']);

        session()->flash('status', "身分「{$role->name}」建立成功。");
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
            abort(403, '系統內建身分的權限無法調整。');
        }

        if ($role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
            $granted = false;
        } else {
            $role->givePermissionTo($permission);
            $granted = true;
        }

        // 權限異動是稽核價值最高的一類：它決定了誰能進哪個後台頁面。
        AuditLog::admin($granted ? '身分新增權限' : '身分移除權限', [
            'role_id' => $role->id,
            'role' => $role->name,
            'permission' => $permission,
        ], $role);
    }

    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)) {
            abort(403, '系統內建身分無法刪除。');
        }

        if ($role->users()->exists()) {
            session()->flash('error', "身分「{$role->name}」目前仍有帳號使用中，無法刪除。");

            return;
        }

        // 先記再刪，理由同 UserManager::deleteUser()。
        AuditLog::admin('刪除身分', [
            'role_id' => $role->id,
            'role' => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ]);

        $role->delete();

        session()->flash('status', "身分「{$role->name}」已刪除。");
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
