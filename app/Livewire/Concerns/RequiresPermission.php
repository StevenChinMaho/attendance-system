<?php

namespace App\Livewire\Concerns;

/**
 * 這幾個管理元件都掛在 routes/web.php 對應的 can:xxx middleware底下，
 * 但 Livewire 元件不是每次互動都會重跑整個路由 middleware 堆疊——只有
 * 收錄在 Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware
 * 那份 allowlist 裡的 middleware 才會延續到之後每一次 wire:click 送出
 * 的互動請求（那份 allowlist 剛好包含 Illuminate\Auth\Middleware\
 * Authorize，也就是 can: middleware 底層用的類別——所以這裡選擇路由層
 * 用 Laravel 原生的 can:permission-name，而不是 spatie 自帶的 role/
 * permission middleware，後者不在allowlist 裡，只會在整頁載入那一刻
 * 檢查一次）。
 *
 * 即使如此，這裡仍然保留元件層級的檢查，原因有二：一是防禦性設計，
 * 不依賴「剛好」在 allowlist 裡這個實作細節；二是 Livewire::test() 從
 * 頭到尾不會觸發 PersistentMiddleware（它明確跳過「fake requests such
 * as a test」），代表只有這裡的 boot 檢查會真的被測試涵蓋到。
 *
 * bootRequiresPermission() 是 Livewire 的 per-trait 生命週期 hook 命名
 * 慣例（boot + trait 的 class_basename），在 mount() 跟之後每一次
 * hydrate()（也就是每一次互動請求）都會重跑。使用此 trait 的元件
 * 必須宣告一個 $requiredPermission 屬性，指定這個頁面對應哪一個
 * 頁面級權限（見 database/seeders/RolePermissionSeeder.php）。
 *
 * @property string $requiredPermission
 */
trait RequiresPermission
{
    public function bootRequiresPermission(): void
    {
        abort_unless(auth()->user()?->can($this->requiredPermission), 403);
    }
}
