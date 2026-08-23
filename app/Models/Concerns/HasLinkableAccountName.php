<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * 「業務身份」表（老師、學生）如果連結了登入帳號，顯示用的姓名一律以
 * 該帳號目前的 users.name 為準，不是資料表自己欄位存的手動輸入值——
 * 帳號本身就有姓名了，分開輸入容易兩邊打成不一樣的字，之後畫面上
 * （帳號管理 vs. 教師/學生管理）看到的名字對不起來也搞不清楚哪個才
 * 對；如果之後系統長出「帳號改名」的功能，這裡也不用另外補資料同步。
 * 只有完全不連結帳號的資料（不需要登入）才會用到自己欄位存的名字。
 *
 * 用這個 trait 的 model 必須：(1) 有一個 `user()` belongsTo 關聯，
 * (2) 實作 manualNameColumn() 回傳「沒有連結帳號時，手動輸入的姓名
 * 存在哪個欄位」（Teacher 是 teacher_name，Student 直接是 name）。
 */
trait HasLinkableAccountName
{
    abstract protected static function manualNameColumn(): string;

    public static function resolveName(?int $userId, ?string $manualName): string
    {
        if ($userId) {
            return User::findOrFail($userId)->name;
        }

        return (string) $manualName;
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->{static::manualNameColumn()};
    }
}
