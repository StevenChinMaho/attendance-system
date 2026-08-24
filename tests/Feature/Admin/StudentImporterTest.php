<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\StudentImporter;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\AcademicPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class StudentImporterTest extends TestCase
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
     * 學校匯出的欄位順序：學號, 班級, 座號, 姓名, 性別, 身分證, 生日
     * ——後兩欄這個系統用不到，測試檔案照樣帶著，確認它們真的被忽略。
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $rows, bool $withHeader = true): File
    {
        $lines = $withHeader ? ['學號,班級,座號,姓名,性別,身分證,生日'] : [];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        // UploadedFile::fake()->createWithContent() 而不是自己 new 一個
        // UploadedFile：Livewire 的測試工具會讀 $file->name，那是
        // Illuminate\Http\Testing\File 才有的屬性。
        return UploadedFile::fake()->createWithContent('students.csv', implode("\n", $lines)."\n");
    }

    private function classIn(int $grade, int $classNumber): SchoolClass
    {
        return SchoolClass::factory()->create([
            'academic_year' => AcademicPeriod::currentYear(),
            'semester' => AcademicPeriod::currentSemester(),
            'grade' => $grade,
            'class_number' => $classNumber,
        ]);
    }

    public function test_guest_is_redirected_away_from_the_import_page(): void
    {
        $this->get('/admin/students/import')->assertRedirect('/');
    }

    public function test_non_admin_is_forbidden_from_the_import_page(): void
    {
        $rep = User::factory()->create();
        $rep->assignRole('student_rep');

        $this->actingAs($rep)->get('/admin/students/import')->assertForbidden();
    }

    public function test_importing_creates_students_and_puts_them_in_their_class(): void
    {
        $class = $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([
                ['1520101', '101', '1', '王小明', '女', 'A123', '2011-01-01'],
                ['1520102', '101', '2', '吳小華', '男', 'A124', '2011-02-02'],
            ]))
            ->call('import');

        $this->assertDatabaseHas('students', ['student_number' => '1520101', 'name' => '王小明', 'gender' => '女']);
        $this->assertSame(2, $class->students()->count());
        $this->assertSame('1', $class->students()->where('student_number', '1520101')->first()->pivot->seat_number);
    }

    public function test_the_header_row_is_not_imported_as_a_student(): void
    {
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '王小明', '女', 'A123', '2011-01-01']]))
            ->call('import');

        $this->assertSame(1, Student::count());
    }

    public function test_a_file_without_a_header_row_still_imports_its_first_student(): void
    {
        // 標題列是靠「第一格含有『學號』」認出來的，不是靠「第一列一定
        // 是標題」猜——萬一某次匯出沒有標題列，第一位學生不該被跳過。
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '王小明', '女']], withHeader: false))
            ->call('import');

        $this->assertDatabaseHas('students', ['student_number' => '1520101']);
    }

    public function test_a_two_digit_class_number_resolves_to_the_right_class(): void
    {
        // 211 = 2年11班，不是 2年1班——見 App\Support\ClassCode。
        $wrongClass = $this->classIn(2, 1);
        $rightClass = $this->classIn(2, 11);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '211', '1', '王小明', '女']]))
            ->call('import');

        $this->assertSame(1, $rightClass->students()->count());
        $this->assertSame(0, $wrongClass->students()->count());
    }

    public function test_an_existing_student_is_added_to_the_new_class_without_being_duplicated(): void
    {
        // 每學期重新匯入全校名單時，絕大多數列都是「去年就存在的學生，
        // 今年換了班級」——這正是多對多要處理的主要情境。
        $oldClass = $this->classIn(1, 1);
        $newClass = $this->classIn(2, 1);
        $student = Student::factory()->forClass($oldClass, '1')->create(['student_number' => '1520101']);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '201', '5', '王小明', '女']]))
            ->call('import');

        $this->assertSame(1, Student::where('student_number', '1520101')->count());
        $this->assertCount(2, $student->fresh()->schoolClasses);
        $this->assertSame('5', $newClass->students()->first()->pivot->seat_number);
        // 舊班級那筆連結原封不動，舊班級的名冊不會因此清空。
        $this->assertSame(1, $oldClass->students()->count());
    }

    public function test_an_existing_students_name_is_never_overwritten_by_the_file(): void
    {
        // 使用者確認的行為決定：學號已存在的列一律不覆蓋既有學生資料，
        // 用到舊檔案或打錯欄位時最糟只是白做工，不會洗掉正確的資料。
        $class = $this->classIn(1, 1);
        Student::factory()->create(['student_number' => '1520101', 'name' => '系統裡的正確姓名', 'gender' => '女']);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '檔案裡打錯的名字', '男']]))
            ->call('import');

        $this->assertDatabaseHas('students', ['student_number' => '1520101', 'name' => '系統裡的正確姓名', 'gender' => '女']);
        $this->assertDatabaseMissing('students', ['name' => '檔案裡打錯的名字']);
        // 但班級連結還是照常補上。
        $this->assertSame(1, $class->students()->count());
    }

    public function test_a_student_already_in_the_class_is_left_untouched(): void
    {
        $class = $this->classIn(1, 1);
        Student::factory()->forClass($class, '1')->create(['student_number' => '1520101']);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '9', '王小明', '女']]))
            ->call('import');

        // 座號也不會被檔案改掉——已在班級的列一律不動。
        $this->assertSame(1, $class->students()->count());
        $this->assertSame('1', $class->students()->first()->pivot->seat_number);
    }

    public function test_a_row_whose_class_does_not_exist_blocks_the_whole_batch(): void
    {
        // 第二個決定：只要有任何一列有問題，整批都不寫入，保證匯入結果
        // 跟預覽表看到的完全一致。
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([
                ['1520101', '101', '1', '王小明', '女'],
                ['1520102', '999', '1', '吳小華', '男'],
            ]))
            ->call('import');

        $this->assertSame(0, Student::count());
    }

    public function test_a_class_from_another_academic_period_is_not_matched(): void
    {
        // 匯入範圍鎖定在導覽列目前選取的學年度／學期，避免手滑把整批
        // 學生匯到別的學年度去。
        SchoolClass::factory()->create([
            'academic_year' => AcademicPeriod::currentYear() - 1,
            'semester' => 1, 'grade' => 1, 'class_number' => 1,
        ]);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '王小明', '女']]))
            ->call('import');

        $this->assertSame(0, Student::count());
    }

    public function test_an_invalid_gender_blocks_the_batch(): void
    {
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '王小明', '不明']]))
            ->call('import');

        $this->assertSame(0, Student::count());
    }

    public function test_a_duplicate_student_number_inside_the_file_blocks_the_batch(): void
    {
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([
                ['1520101', '101', '1', '王小明', '女'],
                ['1520101', '101', '2', '吳小華', '男'],
            ]))
            ->call('import');

        $this->assertSame(0, Student::count());
    }

    public function test_a_duplicate_seat_number_inside_the_file_blocks_the_batch(): void
    {
        $this->classIn(1, 1);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([
                ['1520101', '101', '1', '王小明', '女'],
                ['1520102', '101', '1', '吳小華', '男'],
            ]))
            ->call('import');

        $this->assertSame(0, Student::count());
    }

    public function test_a_seat_number_already_used_in_the_class_blocks_the_batch(): void
    {
        $class = $this->classIn(1, 1);
        Student::factory()->forClass($class, '1')->create(['student_number' => '9999999']);

        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([['1520101', '101', '1', '王小明', '女']]))
            ->call('import');

        $this->assertDatabaseMissing('students', ['student_number' => '1520101']);
    }

    public function test_the_preview_reports_what_each_row_will_do_without_writing_anything(): void
    {
        $class = $this->classIn(1, 1);
        Student::factory()->forClass($class, '1')->create(['student_number' => '1520101']);
        Student::factory()->create(['student_number' => '1520102']);

        $component = Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', $this->csv([
                ['1520101', '101', '1', '王小明', '女'],
                ['1520102', '101', '2', '吳小華', '男'],
                ['1520103', '101', '3', '陳小美', '女'],
                ['1520104', '999', '4', '林小強', '男'],
            ]));

        $this->assertSame(
            ['create' => 1, 'attach' => 1, 'skip' => 1, 'error' => 1],
            $component->viewData('counts')
        );

        // 上傳＋預覽本身完全不寫入任何東西。
        $this->assertSame(2, Student::count());
    }

    public function test_a_non_spreadsheet_upload_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(StudentImporter::class)
            ->set('file', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
            ->assertHasErrors('file');
    }

    public function test_losing_the_permission_mid_session_blocks_the_import(): void
    {
        // Livewire 的更新請求不會重跑路由 middleware，權限要在元件內
        // 每個請求重新檢查——見 App\Livewire\Concerns\RequiresPermission。
        $this->classIn(1, 1);
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(StudentImporter::class);
        $component->assertOk();

        $admin->removeRole('admin');

        $component->call('import')->assertForbidden();
    }
}
