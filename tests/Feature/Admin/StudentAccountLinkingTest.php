<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\StudentManager;
use App\Livewire\Admin\TeacherManager;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * 把登入帳號連結到學生／老師的三條路徑：從學生列直接建帳號、同名一鍵
 * 連結、以及可過濾的帳號選單。
 */
class StudentAccountLinkingTest extends TestCase
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
    // 從學生那一列直接建立帳號
    // ---------------------------------------------------------------

    public function test_an_account_can_be_created_straight_from_the_student_row(): void
    {
        $student = Student::factory()->create([
            'student_number' => '10001',
            'name' => '王小明',
        ]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startCreateAccount', $student)
            // 帳號名稱預設帶入學號：全校唯一、學生記得住，之後「帳號對得上
            // 哪位學生」一眼看得出來。
            ->assertSet('newAccountUsername', '10001')
            ->set('newAccountPassword', 'a-strong-password')
            ->call('createAccountForStudent');

        $user = User::where('username', '10001')->sole();

        // 姓名自動沿用學生資料，不用再打一次
        $this->assertSame('王小明', $user->name);
        $this->assertTrue($user->hasRole('student_rep'));
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));

        // 而且已經連結好了，不必再回頭做一次
        $this->assertSame($user->id, $student->fresh()->user_id);
    }

    public function test_creating_an_account_is_written_to_the_audit_log(): void
    {
        $student = Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startCreateAccount', $student)
            ->set('newAccountPassword', 'a-strong-password')
            ->call('createAccountForStudent');

        $log = Activity::where('log_name', AuditLog::ADMIN)->latest('id')->first();

        $this->assertSame('建立帳號', $log->description);
        $this->assertSame('10001', $log->properties['username']);
        $this->assertSame('student_rep', $log->properties['role']);
        $this->assertStringNotContainsString('a-strong-password', json_encode($log->properties));
    }

    public function test_a_duplicate_username_is_rejected(): void
    {
        User::factory()->create(['username' => '10001']);
        $student = Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startCreateAccount', $student)
            ->set('newAccountPassword', 'a-strong-password')
            ->call('createAccountForStudent')
            ->assertHasErrors(['newAccountUsername']);

        $this->assertNull($student->fresh()->user_id);
    }

    public function test_a_short_password_is_rejected(): void
    {
        $student = Student::factory()->create(['student_number' => '10001']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startCreateAccount', $student)
            ->set('newAccountPassword', 'short')
            ->call('createAccountForStudent')
            ->assertHasErrors(['newAccountPassword']);

        $this->assertNull($student->fresh()->user_id);
    }

    /**
     * 這個方法是 wire:click 的目標，可以被直接呼叫——畫面上按鈕只在
     * 未連結時顯示，但伺服器端仍要自己確認一次，否則會把學生原本的
     * 連結蓋掉。
     */
    public function test_a_student_who_already_has_an_account_is_not_overwritten(): void
    {
        $existing = User::factory()->create(['username' => 'existing']);
        $student = Student::factory()->create(['student_number' => '10001', 'user_id' => $existing->id]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->set('creatingAccountStudentId', $student->id)
            ->set('newAccountUsername', 'brand-new')
            ->set('newAccountPassword', 'a-strong-password')
            ->call('createAccountForStudent');

        $this->assertSame($existing->id, $student->fresh()->user_id);
        $this->assertDatabaseMissing('users', ['username' => 'brand-new']);
    }

    /**
     * 這一頁的列級狀態現在有五種（新增／編輯／標記轉出／恢復在讀／建立
     * 帳號），彼此共用畫面上同一列。任何一個開啟時都要把其他關掉，
     * 否則會同時展開兩個表單——見 CLAUDE.md 記載的既有問題。
     */
    public function test_opening_the_account_panel_closes_the_other_row_states(): void
    {
        $student = Student::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startEdit', $student)
            ->assertSet('editingStudentId', $student->id)
            ->call('startCreateAccount', $student)
            ->assertSet('editingStudentId', null)
            ->assertSet('creatingAccountStudentId', $student->id);

        // 反過來也要成立
        $component->call('startMarkAsLeft', $student)
            ->assertSet('creatingAccountStudentId', null);

        $component->call('startCreateAccount', $student)
            ->assertSet('markingLeftStudentId', null);

        $component->call('toggleCreateForm')
            ->assertSet('creatingAccountStudentId', null)
            ->assertSet('showCreateForm', true);
    }

    // ---------------------------------------------------------------
    // 同名一鍵連結
    // ---------------------------------------------------------------

    public function test_an_unlinked_account_with_the_same_name_is_suggested(): void
    {
        $account = User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->assertSee('連結 wang01');

        $this->assertNotNull($account);
    }

    public function test_the_suggested_account_can_be_linked_in_one_click(): void
    {
        $account = User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        $student = Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('linkSuggestedAccount', $student, $account->id);

        $this->assertSame($account->id, $student->fresh()->user_id);
    }

    /**
     * 三百多人的學校幾乎必然有同名同姓。多個同名候選時自動挑一個，就會
     * 出現「A 的帳號連到 B 身上」這種很難察覺又很難查的錯，所以只在
     * 「恰好一個」時才給建議。
     */
    public function test_no_suggestion_is_offered_when_several_accounts_share_the_name(): void
    {
        User::factory()->create(['name' => '陳怡君', 'username' => 'chen01']);
        User::factory()->create(['name' => '陳怡君', 'username' => 'chen02']);
        Student::factory()->create(['student_number' => '10001', 'name' => '陳怡君']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->assertDontSee('連結 chen01')
            ->assertDontSee('連結 chen02');
    }

    public function test_an_account_that_is_already_linked_is_never_suggested(): void
    {
        $account = User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        Teacher::factory()->create(['user_id' => $account->id, 'teacher_name' => '王小明']);
        Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->assertDontSee('連結 wang01');
    }

    /**
     * linkSuggestedAccount() 的參數完全由客戶端決定。這個功能的定義是
     * 「同名配對」，所以伺服器端必須自己再確認姓名真的一致——要任意
     * 指定請走編輯表單。
     */
    public function test_linking_an_account_with_a_different_name_is_refused(): void
    {
        $account = User::factory()->create(['name' => '完全不同的人', 'username' => 'other']);
        $student = Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('linkSuggestedAccount', $student, $account->id);

        $this->assertNull($student->fresh()->user_id);
    }

    public function test_linking_an_already_linked_account_is_refused(): void
    {
        $account = User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        Teacher::factory()->create(['user_id' => $account->id, 'teacher_name' => '王小明']);
        $student = Student::factory()->create(['student_number' => '10001', 'name' => '王小明']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('linkSuggestedAccount', $student, $account->id);

        $this->assertNull($student->fresh()->user_id);
    }

    // ---------------------------------------------------------------
    // 可過濾的帳號選單
    // ---------------------------------------------------------------

    public function test_the_account_picker_can_be_filtered(): void
    {
        User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        User::factory()->create(['name' => '陳大文', 'username' => 'chen01']);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('toggleCreateForm')
            ->set('accountSearch', 'chen01')
            ->assertSee('chen01')
            ->assertDontSee('wang01');
    }

    /**
     * 目前已選中的帳號一定要留在清單裡：不然一開始過濾，正在編輯的那筆
     * 連結就會從 select 掉出去，看起來像被清空、一送出就真的被清掉。
     */
    public function test_the_currently_selected_account_survives_filtering(): void
    {
        $linked = User::factory()->create(['name' => '王小明', 'username' => 'wang01']);
        $student = Student::factory()->create(['student_number' => '10001', 'user_id' => $linked->id]);

        Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('startEdit', $student)
            ->set('accountSearch', '完全搜不到的字串')
            ->assertSee('wang01');
    }

    public function test_the_picker_is_capped_but_search_still_reaches_further(): void
    {
        User::factory()->count(60)->create();
        $needle = User::factory()->create(['name' => 'ZZZ 最後一個', 'username' => 'needle-account']);

        $component = Livewire::actingAs($this->admin())
            ->test(StudentManager::class)
            ->call('toggleCreateForm');

        // 名字排在最後，一定落在 50 筆上限之外
        $component->assertDontSee('needle-account');

        $component->set('accountSearch', 'needle-account')->assertSee('needle-account');

        $this->assertNotNull($needle);
    }

    public function test_the_teacher_account_picker_can_also_be_filtered(): void
    {
        User::factory()->create(['name' => '王老師', 'username' => 'wang-teacher']);
        User::factory()->create(['name' => '陳老師', 'username' => 'chen-teacher']);

        Livewire::actingAs($this->admin())
            ->test(TeacherManager::class)
            ->call('toggleCreateForm')
            ->set('accountSearch', 'chen-teacher')
            ->assertSee('chen-teacher')
            ->assertDontSee('wang-teacher');
    }
}
