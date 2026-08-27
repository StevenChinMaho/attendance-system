<?php

namespace Tests\Feature\Admin;

use App\Enums\AttendanceStatus;
use App\Livewire\Admin\AuditLogViewer;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
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

    // ---------------------------------------------------------------
    // 權限邊界
    // ---------------------------------------------------------------

    public function test_guest_is_redirected_away(): void
    {
        $this->get('/admin/audit')->assertRedirect('/');
    }

    public function test_a_role_without_the_permission_is_forbidden(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('homeroom_teacher');

        $this->actingAs($teacher)->get('/admin/audit')->assertForbidden();
    }

    public function test_an_admin_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/audit')->assertOk();
    }

    /**
     * 稽核查閱是獨立的權限，不綁在 admin 這個角色上——學務處可能需要
     * 查紀錄但不該能改帳號。自訂身分只勾 audit.view 就要能進得來。
     */
    public function test_a_custom_role_with_only_audit_view_can_open_the_page(): void
    {
        $role = Role::create(['name' => '學務處人員', 'guard_name' => 'web']);
        $role->givePermissionTo('audit.view');

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->get('/admin/audit')->assertOk();

        // 反過來：只有這個權限，其他後台頁面仍然進不去。
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    /**
     * 元件層級的授權要獨立於路由 middleware 存在——Livewire 的後續
     * 互動請求不會重跑大部分路由 middleware（見 CLAUDE.md），而
     * Livewire::test() 本來就不經過路由。
     */
    public function test_the_component_authorises_on_its_own(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('homeroom_teacher');

        Livewire::actingAs($teacher)
            ->test(AuditLogViewer::class)
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // 顯示與篩選
    // ---------------------------------------------------------------

    public function test_it_renders_a_readable_summary_for_each_log_shape(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        AuditLog::attendanceSession('點名單送出', [
            'school_class' => '1年3班',
            'date' => '2026-08-27',
            'period_label' => '上午',
            'student_count' => 30,
            'status_counts' => [
                AttendanceStatus::Present->value => 29,
                AttendanceStatus::Absent->value => 1,
            ],
        ]);

        AuditLog::attendanceRecord('出席狀態變更', [
            'school_class' => '1年3班',
            'student_number' => '10001',
            'student_name' => '王小明',
            'old' => AttendanceStatus::Absent->value,
            'new' => AttendanceStatus::Late->value,
        ]);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            // 狀態值要翻成中文，不是原始的 ABSENT/LATE
            ->assertSee('缺席 → 遲到')
            ->assertSee('10001 王小明')
            ->assertSee('出席 29、缺席 1')
            ->assertDontSee('EARLY_LEAVE');
    }

    public function test_an_anonymous_entry_is_labelled_rather_than_left_blank(): void
    {
        AuditLog::auth('登入失敗：帳號不存在', null, ['username' => null]);

        Livewire::actingAs($this->admin())
            ->test(AuditLogViewer::class)
            ->assertSee('未登入');
    }

    public function test_the_category_filter_narrows_the_list(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::auth('登入成功', $admin, ['username' => 'someone']);
        AuditLog::admin('建立班級', ['school_class' => '2年5班']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('categoryFilter', AuditLog::ADMIN)
            ->assertSee('2年5班')
            ->assertDontSee('登入成功');
    }

    /**
     * $categoryFilter 是 public 屬性、客戶端可改寫。白名單以外的值要被
     * 忽略（顯示全部），而不是變成一個空結果或錯誤。
     */
    public function test_an_unknown_category_filter_is_ignored(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('categoryFilter', "no_such_log'; drop table activity_log; --")
            ->assertOk()
            ->assertSee('2年5班');
    }

    public function test_the_causer_filter_narrows_to_one_person(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['name' => '林老師', 'username' => 'lin']);

        $this->actingAs($admin);
        AuditLog::admin('建立班級', ['school_class' => '2年5班']);

        $this->actingAs($other);
        AuditLog::admin('刪除班級', ['school_class' => '3年1班']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('causerFilter', (string) $other->id)
            ->assertSee('3年1班')
            ->assertDontSee('2年5班');
    }

    /**
     * 「到 8/27」必須包含 8/27 當天。用 >= / <= 直接比 timestamp 的話，
     * '2026-08-27' 會被當成 00:00:00，當天的紀錄會全部被排除掉。
     */
    public function test_the_end_date_includes_that_whole_day(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);
        Activity::query()->update(['created_at' => '2026-08-27 15:30:00']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('fromDate', '2026-08-27')
            ->set('toDate', '2026-08-27')
            ->assertSee('2年5班');
    }

    public function test_the_date_range_excludes_entries_outside_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);
        Activity::query()->update(['created_at' => '2026-08-20 09:00:00']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('fromDate', '2026-08-27')
            ->assertDontSee('2年5班');
    }

    /**
     * properties 在資料庫裡是 Laravel json_encode 的結果，中文被逃逸成
     * \uXXXX（實測：「3年1班」存成 {"school_class":"3年1班"}）。
     * 直接對這個欄位 LIKE 中文一筆都比對不到，而且是安靜地回傳空結果
     * ——看起來就像「沒有這筆紀錄」。班級與姓名正是最常搜的東西，所以
     * 這條測試是在守住「中文搜尋真的有效」。
     */
    public function test_search_matches_chinese_text_inside_the_properties_json(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);
        AuditLog::admin('建立班級', ['school_class' => '3年1班']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('search', '3年1班')
            ->assertSee('3年1班')
            ->assertDontSee('2年5班');
    }

    public function test_search_also_matches_ascii_and_the_description(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立帳號', ['username' => 'wang-teacher']);
        AuditLog::admin('刪除班級', ['school_class' => '3年1班']);

        $component = Livewire::actingAs($admin)->test(AuditLogViewer::class);

        $component->set('search', 'wang-teacher')
            ->assertSee('wang-teacher')
            ->assertDontSee('3年1班');

        // 動作描述本身也要搜得到
        $component->set('search', '刪除班級')
            ->assertSee('3年1班')
            ->assertDontSee('wang-teacher');
    }

    public function test_details_can_be_expanded_and_collapsed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::auth('登入成功', $admin, ['username' => 'sysadmin']);
        $id = Activity::query()->latest('id')->first()->id;

        $component = Livewire::actingAs($admin)->test(AuditLogViewer::class);

        // 明細收合時不該看到欄位標籤
        $component->assertDontSee('來源 IP');

        $component->call('toggleDetails', $id)->assertSee('來源 IP');
        $component->call('toggleDetails', $id)->assertDontSee('來源 IP');
    }

    public function test_clear_filters_restores_everything(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('search', '不存在的東西')
            ->assertDontSee('2年5班')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('2年5班');
    }

    /**
     * 這一頁沒有任何寫入動作——稽核紀錄能當憑據的前提就是它不會被畫面
     * 改動或刪除。這條測試是防止之後有人「順手」加上刪除按鈕。
     */
    public function test_the_page_offers_no_way_to_modify_records(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        AuditLog::admin('建立班級', ['school_class' => '2年5班']);

        $html = Livewire::actingAs($admin)->test(AuditLogViewer::class)->html();

        // 不能只斷言「HTML 裡沒有『刪除』」——稽核紀錄本身就會記錄
        // 「刪除帳號」這類動作，那些字理所當然會出現在畫面上。要檢查的
        // 是「有沒有可以按下去改動紀錄的東西」，所以看 wire:click 實際
        // 指向哪些方法。
        preg_match_all('/wire:click="([a-zA-Z]+)/', $html, $matches);
        $calledMethods = array_unique($matches[1]);

        $this->assertEqualsCanonicalizing(
            ['toggleDetails'],
            array_values(array_diff($calledMethods, ['previousPage', 'nextPage', 'gotoPage'])),
            '稽核紀錄頁面只該有展開明細與翻頁，不該有任何會改動紀錄的操作。',
        );

        $this->assertStringNotContainsString('wire:confirm', $html);

        // 元件本身也不該長出寫入用的方法（wire:click 是可以被直接呼叫的）。
        $methods = get_class_methods(AuditLogViewer::class);
        foreach (['delete', 'destroy', 'purge', 'remove'] as $forbidden) {
            $this->assertEmpty(
                array_filter($methods, fn (string $m) => str_starts_with(strtolower($m), $forbidden)),
                "稽核紀錄頁面不應該有 {$forbidden}* 這類會改動紀錄的方法。",
            );
        }
    }
}
