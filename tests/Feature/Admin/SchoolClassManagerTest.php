<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\SchoolClassManager;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolClassManagerTest extends TestCase
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

    public function test_guest_is_redirected_away_from_the_classes_page(): void
    {
        $this->get('/admin/classes')->assertRedirect('/');
    }

    public function test_non_admin_is_forbidden_from_the_classes_page(): void
    {
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)->get('/admin/classes')->assertForbidden();
    }

    public function test_admin_can_create_a_class_in_the_currently_selected_academic_period(): void
    {
        // 新增班級的學年度／學期不是表單自由輸入的，而是鎖定成 nav bar
        // 目前選取的那個學年度／學期（見 App\Support\AcademicPeriod），
        // 這裡透過切換選單會寫入的 session 值來模擬「使用者已經切換到
        // 113 學年度上學期」。
        AcademicPeriod::setSelected(113, 1);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('grade', '1')
            ->set('classNumber', '3')
            ->call('createClass')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_classes', [
            'academic_year' => 113,
            'semester' => 1,
            'grade' => 1,
            'class_number' => '3',
        ]);
    }

    public function test_duplicate_class_within_the_same_academic_period_fails_validation(): void
    {
        AcademicPeriod::setSelected(113, 1);

        SchoolClass::factory()->create([
            'academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '3',
        ]);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('grade', '1')
            ->set('classNumber', '3')
            ->call('createClass')
            ->assertHasErrors('classNumber');
    }

    public function test_same_class_number_in_a_different_academic_year_is_allowed(): void
    {
        SchoolClass::factory()->create([
            'academic_year' => 112, 'semester' => 1, 'grade' => 1, 'class_number' => '3',
        ]);

        AcademicPeriod::setSelected(113, 1);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('grade', '1')
            ->set('classNumber', '3')
            ->call('createClass')
            ->assertHasNoErrors();
    }

    public function test_classes_outside_the_selected_academic_period_are_not_listed(): void
    {
        SchoolClass::factory()->create(['academic_year' => 112, 'semester' => 1, 'grade' => 1, 'class_number' => '99']);

        AcademicPeriod::setSelected(113, 1);
        SchoolClass::factory()->create(['academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '5']);

        $classNumbers = Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->viewData('classes')
            ->pluck('class_number')
            ->all();

        $this->assertSame(['5'], $classNumbers);
    }

    public function test_classes_are_listed_in_natural_numeric_order_not_string_order(): void
    {
        AcademicPeriod::setSelected(113, 1);

        SchoolClass::factory()->create(['academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '10']);
        SchoolClass::factory()->create(['academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '2']);

        $labels = Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->viewData('classes')
            ->pluck('class_number')
            ->all();

        $this->assertSame(['2', '10'], $labels);
    }

    public function test_admin_can_assign_a_homeroom_teacher(): void
    {
        $class = SchoolClass::factory()->create();
        $teacher = Teacher::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('startEdit', $class->id)
            ->set('homeroomTeacherId', $teacher->id)
            ->call('updateClass')
            ->assertHasNoErrors();

        $this->assertSame($teacher->id, $class->fresh()->homeroom_teacher_id);
    }

    public function test_opening_the_create_form_while_editing_closes_the_edit_form(): void
    {
        // 新增表單跟編輯表單共用同一組欄位屬性（$grade/$classNumber/
        // $homeroomTeacherId）；兩個表單以前可以同時開著，輸入內容看
        // 起來會互相同步，實際上是同一個屬性被兩邊的輸入框綁定。
        $class = SchoolClass::factory()->create(['grade' => 1, 'class_number' => '9']);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('startEdit', $class->id)
            ->assertSet('editingClassId', $class->id)
            ->call('toggleCreateForm')
            ->assertSet('showCreateForm', true)
            ->assertSet('editingClassId', null)
            ->assertSet('classNumber', '');
    }

    public function test_creating_a_class_while_another_is_being_edited_does_not_duplicate_its_data(): void
    {
        // 修正前：編輯表單開著時點「新增班級」，新增表單會沿用正在編輯
        // 那個班級的年級／代號，同學年度學期底下撞到 unique 限制噴 500。
        AcademicPeriod::setSelected(113, 1);
        $existing = SchoolClass::factory()->create([
            'academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '9',
        ]);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('startEdit', $existing->id)
            ->call('toggleCreateForm')
            ->set('grade', '2')
            ->set('classNumber', '3')
            ->call('createClass')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_classes', [
            'academic_year' => 113, 'semester' => 1, 'grade' => 2, 'class_number' => '3',
        ]);
        $this->assertSame('9', $existing->fresh()->class_number);
    }

    public function test_quick_added_teacher_is_created_and_selected_into_the_currently_open_form(): void
    {
        // 以前一定要先跳到「教師管理」把老師建好才能回這裡選——這裡驗證
        // 內嵌的快速新增面板真的會建立老師，並且自動選進正在開著的表單
        // （新增班級）的 homeroomTeacherId，不用整個離開這一頁。
        $component = Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('toggleCreateForm')
            ->call('toggleQuickAddTeacher')
            ->set('newTeacherName', '快速新增的老師')
            ->call('quickAddTeacher')
            ->assertHasNoErrors()
            ->assertSet('showQuickAddTeacher', false);

        $teacher = Teacher::where('teacher_name', '快速新增的老師')->firstOrFail();

        $component->assertSet('homeroomTeacherId', $teacher->id);
    }

    public function test_quick_add_teacher_links_an_account_and_uses_its_name(): void
    {
        $account = User::factory()->create(['name' => '帳號的姓名']);

        $component = Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('toggleCreateForm')
            ->call('toggleQuickAddTeacher')
            ->set('newTeacherUserId', $account->id)
            ->call('quickAddTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', ['teacher_name' => '帳號的姓名', 'user_id' => $account->id]);

        $teacher = Teacher::where('user_id', $account->id)->firstOrFail();
        $component->assertSet('homeroomTeacherId', $teacher->id);
    }

    public function test_quick_add_teacher_name_is_required_when_no_account_is_linked(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->call('toggleCreateForm')
            ->call('toggleQuickAddTeacher')
            ->set('newTeacherName', '')
            ->call('quickAddTeacher')
            ->assertHasErrors('newTeacherName');
    }

    public function test_the_classes_table_no_longer_offers_a_take_attendance_link(): void
    {
        // 點名入口統一收斂到 nav bar 的 AttendanceQuickLink，管理者不再
        // 需要（也不該）從班級管理列表直接點進點名頁——見
        // resources/views/components/nav-bar.blade.php。
        $class = SchoolClass::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->assertDontSee(route('attendance.show', $class), false);
    }
}
