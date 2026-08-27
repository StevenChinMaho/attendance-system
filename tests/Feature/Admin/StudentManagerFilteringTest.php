<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\StudentManager;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentDeparture;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 學生管理的搜尋與篩選。建立／編輯／轉出／刪除與權限邊界在 StudentManagerTest。
 */
class StudentManagerFilteringTest extends TestCase
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

    public function test_search_matches_the_student_number(): void
    {
        $admin = $this->admin();
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);
        Student::factory()->create(['student_number' => '20002', 'name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('search', '20002')
            ->assertSee('陳大文')
            ->assertDontSee('王小明');
    }

    public function test_search_matches_the_name(): void
    {
        $admin = $this->admin();
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);
        Student::factory()->create(['student_number' => '20002', 'name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('search', '王小明')
            ->assertSee('10001')
            ->assertDontSee('20002');
    }

    public function test_search_matches_the_linked_accounts_username(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create(['name' => '王小明', 'username' => 'rep-a']);
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明', 'user_id' => $account->id]);
        Student::factory()->create(['student_number' => '20002', 'name' => '陳大文']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('search', 'rep-a')
            ->assertSee('10001')
            ->assertDontSee('20002');
    }

    public function test_the_gender_filter_narrows_the_list(): void
    {
        $admin = $this->admin();
        Student::factory()->create(['student_number' => '10001', 'name' => '男同學', 'gender' => '男']);
        Student::factory()->create(['student_number' => '20002', 'name' => '女同學', 'gender' => '女']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('genderFilter', '女')
            ->assertSee('女同學')
            ->assertDontSee('男同學');
    }

    /**
     * 班級篩選選單的順序要跟班級管理頁一致：年級優先、再班級編號。
     * 只排 class_number 的話會變成 1年1班、3年1班、2年1班、… 這種
     * 「所有 1 號班擠在最前面」的順序（實際回報過的問題）。
     */
    public function test_the_class_filter_options_are_in_natural_class_order(): void
    {
        $admin = $this->admin();

        SchoolClass::factory()->create(['grade' => 3, 'class_number' => 1]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 2]);
        SchoolClass::factory()->create(['grade' => 2, 'class_number' => 1]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 1]);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->assertSeeInOrder(['1年1班', '1年2班', '2年1班', '3年1班']);
    }

    public function test_the_class_filter_narrows_to_one_class(): void
    {
        $admin = $this->admin();
        $classA = SchoolClass::factory()->create();
        $classB = SchoolClass::factory()->create();

        Student::factory()->forClass($classA)->create(['student_number' => '10001', 'name' => '甲班同學']);
        Student::factory()->forClass($classB)->create(['student_number' => '20002', 'name' => '乙班同學']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('classFilter', (string) $classA->id)
            ->assertSee('甲班同學')
            ->assertDontSee('乙班同學');
    }

    /**
     * 「未加入班級」是刻意做出來的特例：剛匯入完但還沒編班的學生正是
     * 最需要被找出來處理的一群，沒有這個選項就只能一頁一頁翻著找。
     */
    public function test_the_class_filter_can_show_students_without_a_class(): void
    {
        $admin = $this->admin();
        $schoolClass = SchoolClass::factory()->create();

        Student::factory()->forClass($schoolClass)->create(['student_number' => '10001', 'name' => '已編班']);
        Student::factory()->create(['student_number' => '20002', 'name' => '還沒編班']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('classFilter', 'none')
            ->assertSee('還沒編班')
            ->assertDontSee('已編班');
    }

    /**
     * 「未加入班級」是相對於「目前選取的學年度／學期」而言的——一個學生
     * 去年有班級、今年還沒編班，在今年的畫面上就該算未編班。
     */
    public function test_a_class_from_another_period_does_not_count_as_having_a_class(): void
    {
        $admin = $this->admin();
        $lastYearClass = SchoolClass::factory()->create([
            'academic_year' => AcademicPeriod::currentYear() - 1,
            'semester' => 1,
        ]);

        Student::factory()->forClass($lastYearClass)->create(['student_number' => '10001', 'name' => '去年有班級']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('classFilter', 'none')
            ->assertSee('去年有班級');
    }

    public function test_the_status_filter_separates_enrolled_from_departed(): void
    {
        $admin = $this->admin();

        $stayed = Student::factory()->create(['student_number' => '10001', 'name' => '在讀同學']);
        $left = Student::factory()->create(['student_number' => '20002', 'name' => '轉出同學']);
        StudentDeparture::factory()->for($left)->create(['left_at' => now()->subMonth(), 'returned_at' => null]);

        $component = Livewire::actingAs($admin)->test(StudentManager::class);

        $component->set('statusFilter', 'left')
            ->assertSee('轉出同學')
            ->assertDontSee('在讀同學');

        $component->set('statusFilter', 'enrolled')
            ->assertSee('在讀同學')
            ->assertDontSee('轉出同學');

        $this->assertNotNull($stayed);
    }

    /**
     * 已經轉出又轉回來的學生算「在讀」——判斷條件跟 Student::currentDeparture()
     * 一樣，是「有沒有一段還沒結束的轉出期間」，不是「有沒有轉出紀錄」。
     */
    public function test_a_student_who_came_back_counts_as_enrolled(): void
    {
        $admin = $this->admin();

        $returned = Student::factory()->create(['student_number' => '10001', 'name' => '轉回來的同學']);
        StudentDeparture::factory()->for($returned)->create([
            'left_at' => now()->subMonths(3),
            'returned_at' => now()->subMonth(),
        ]);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('statusFilter', 'enrolled')
            ->assertSee('轉回來的同學');
    }

    /**
     * 搜尋條件裡的 OR 一定要用括號包住，不然會跟後面的篩選條件攤平成
     * 同一層，篩選等於整個失效——這是最容易寫錯、而且從畫面上很難察覺
     * 的一種 bug（看起來只是「篩選好像沒生效」）。
     */
    public function test_search_and_filter_are_combined_with_and_not_or(): void
    {
        $admin = $this->admin();
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明', 'gender' => '男']);
        Student::factory()->create(['student_number' => '20002', 'name' => '王大明', 'gender' => '女']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('search', '王')
            ->set('genderFilter', '女')
            ->assertSee('王大明')
            ->assertDontSee('王小明');
    }

    public function test_clear_filters_restores_the_full_list(): void
    {
        $admin = $this->admin();
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($admin)
            ->test(StudentManager::class)
            ->set('search', '不存在的人')
            ->assertDontSee('10001')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('10001');
    }
}
