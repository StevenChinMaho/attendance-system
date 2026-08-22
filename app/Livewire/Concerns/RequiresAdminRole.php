<?php

namespace App\Livewire\Concerns;

/**
 * 這幾個管理元件都掛在 routes/web.php 的 role:admin middleware 底下，
 * 但那只在整頁載入那一刻檢查一次。spatie 的 role/permission middleware
 * 沒有被 Livewire 內部那份 PersistentMiddleware allowlist 收錄
 * （allowlist 只認 Illuminate\Auth\Middleware\Authenticate/Authorize
 * 等框架自帶的類別），代表之後每一次 wire:click 送出的互動請求都不會
 * 重新檢查 role:admin。如果管理者的角色被收回，舊分頁還是能繼續呼叫
 * create/update/toggle 等操作，直到重新整理頁面為止。
 *
 * bootRequiresAdminRole() 是 Livewire 的 per-trait 生命週期 hook 命名
 * 慣例（boot + trait 的 class_basename），在 mount() 跟之後每一次
 * hydrate()（也就是每一次互動請求）都會重跑，用它在真正執行元件方法
 * 之前擋下已經不是 admin 的請求，不依賴 middleware 延續的實作細節，
 * 也真的測得到（Livewire::test() 一樣會觸發這個 hook）。
 */
trait RequiresAdminRole
{
    public function bootRequiresAdminRole(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }
}
