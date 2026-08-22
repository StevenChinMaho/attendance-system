<?php

namespace App\Livewire\Concerns;

/**
 * Recorder（點名）跟 StatusBoard（看板）都需要「有哪些時段」跟「現在
 * 預設是哪個時段」，原本兩邊各自複製一份一模一樣的判斷邏輯，收斂在
 * 這裡避免之後改時段邊界（例如午休時間調整）時只改到一邊。
 *
 * 用一般 class 而不是 trait：PHP 不允許從外部直接存取 trait 的常數
 * （blade view 裡沒辦法寫 SomeTrait::PERIODS，只有真的 use 它的 class
 * 內部能存取），這裡兩個元件的 view 都需要直接引用 PERIODS，所以只能
 * 用一般 class 讓兩邊都能呼叫，不用 use 進元件類別。
 *
 * 目前只開放這三個時段，對應甲方「一天三次」的實際需求。故意不做成
 * 資料庫層 enum，之後若要擴充成逐節點名（1~8節）只需要改這裡的
 * PERIODS 常數跟 current() 的時間判斷，不需要動資料表結構——見
 * system_structure.md「點名顆粒度」的設計討論。
 */
final class AttendancePeriods
{
    public const PERIODS = [
        'MORNING' => '早上',
        'NOON' => '中午',
        'AFTERNOON' => '下午',
    ];

    public static function current(): string
    {
        return match (true) {
            now()->hour < 11 => 'MORNING',
            now()->hour < 15 => 'NOON',
            default => 'AFTERNOON',
        };
    }
}
