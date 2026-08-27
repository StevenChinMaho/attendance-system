<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserManager;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 共用的分頁列（resources/views/vendor/livewire/tailwind.blade.php，
 * 覆寫 Livewire 內建的 livewire::tailwind）。
 *
 * 這裡拿 UserManager 當載體，但測的是那份 view 本身——所有分頁頁面
 * 都吃同一份，不需要每個頁面各測一次。
 */
class PaginationViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * 內建版本整個包在 @if ($paginator->hasPages()) 裡，只有一頁時什麼
     * 都不畫——連「總共幾筆」都看不到。筆數摘要必須在條件外面。
     */
    public function test_the_record_count_is_shown_even_with_a_single_page(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertSee('共 1 筆')
            // 只有一頁時翻頁按鈕沒有意義，不該出現。
            ->assertDontSee('下一頁');
    }

    public function test_it_shows_the_current_page_and_the_total_pages(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertSee('第 1 / 2 頁')
            ->assertSee('第 1–15 筆，共 21 筆')
            ->assertSee('下一頁');
    }

    public function test_the_page_indicator_follows_the_current_page(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('nextPage')
            ->assertSee('第 2 / 2 頁')
            ->assertSee('第 16–21 筆，共 21 筆');
    }

    /**
     * 「翻到底沒有任何視覺提示」是實際回報的問題。第一頁時「上一頁」
     * 必須是 disabled（樣式見 app.css 的 .pagination-btn:disabled），
     * 不是一顆看起來能按、按了卻沒反應的按鈕。
     */
    public function test_the_previous_button_is_disabled_on_the_first_page(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        $html = Livewire::actingAs($admin)->test(UserManager::class)->html();

        // Blade 的 @disabled() 渲染出來是「裸的」disabled，不是
        // disabled="disabled"。用 \sdisabled\s 才能跟同一顆按鈕上的
        // wire:loading.attr="disabled"（那個 disabled 前面是引號）區分開。
        $this->assertMatchesRegularExpression(
            '/<button[^>]*previousPage[^>]*\sdisabled\s/s',
            $html,
            '第一頁時「上一頁」按鈕應該是 disabled。',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*nextPage[^>]*\sdisabled\s/s',
            $html,
            '還有下一頁時「下一頁」按鈕不該是 disabled。',
        );
    }

    public function test_the_next_button_is_disabled_on_the_last_page(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        $html = Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('nextPage')
            ->html();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*nextPage[^>]*\sdisabled\s/s',
            $html,
            '最後一頁時「下一頁」按鈕應該是 disabled。',
        );
    }

    public function test_an_empty_result_says_so_instead_of_showing_a_range(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('search', '這個人不存在')
            ->assertSee('沒有符合的資料');
    }

    /**
     * 分頁列用的是專案自己的 .pagination-* 元件類別（app.css），不是
     * Livewire 內建那份的 gray-* 色階——後者跟全站的 slate-* 放在一起
     * 色調明顯對不上。
     */
    public function test_it_uses_the_projects_own_component_classes(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        $html = Livewire::actingAs($admin)->test(UserManager::class)->html();

        $this->assertStringContainsString('pagination-btn', $html);
        $this->assertStringContainsString('pagination-summary', $html);
        $this->assertStringNotContainsString('text-gray-700', $html);
    }
}
