<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AcademicPeriod;
use Illuminate\Database\Seeder;

/**
 * 本機開發／手動測試用的固定示範資料——不是給正式環境用的帳號建立方式
 * （正式環境帳號一律由管理者在後台建立，見 CLAUDE.md「Auth is
 * username-based」），只在 local/testing 環境跑，見 DatabaseSeeder。
 *
 * 每次 `migrate:fresh --seed` 都會把資料庫清空，手動在畫面上重新建立
 * 班級／導師／學生資料很花時間，這個 seeder 固定建立一組可預期、可以
 * 重複登入測試的資料，取代每次都要手動重建一輪。班級固定建在「目前」
 * 學年度／學期（見 App\Support\AcademicPeriod），這樣預設的學年度篩選
 * 一開啟就能直接看到，不用先切換導覽列。
 *
 * 帳號密碼統一是 UserFactory 預設的 "password"（跟 DatabaseSeeder 建立
 * 的 admin 帳號一致），使用者名稱固定、方便每次重建後直接登入：
 * - teacher1／teacher2：導師（各帶一班）
 * - rep1／rep2：學生帳號（各自班級裡的其中一位學生，有登入帳號）
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicPeriod::currentYear();
        $semester = AcademicPeriod::currentSemester();

        $this->createClassWithTeacherAndRep(
            year: $year,
            semester: $semester,
            grade: 1,
            classNumber: 1,
            teacherUsername: 'teacher1',
            teacherName: '王小明',
            repUsername: 'rep1',
        );

        $this->createClassWithTeacherAndRep(
            year: $year,
            semester: $semester,
            grade: 2,
            classNumber: 3,
            teacherUsername: 'teacher2',
            teacherName: '陳雅婷',
            repUsername: 'rep2',
        );
    }

    private function createClassWithTeacherAndRep(
        int $year,
        int $semester,
        int $grade,
        int $classNumber,
        string $teacherUsername,
        string $teacherName,
        string $repUsername,
    ): void {
        $teacherUser = User::factory()->create([
            'name' => $teacherName,
            'username' => $teacherUsername,
        ]);
        $teacherUser->assignRole('homeroom_teacher');

        $teacher = Teacher::factory()->create([
            'user_id' => $teacherUser->id,
            'teacher_name' => $teacherName,
        ]);

        $class = SchoolClass::factory()->create([
            'academic_year' => $year,
            'semester' => $semester,
            'grade' => $grade,
            'class_number' => $classNumber,
            'homeroom_teacher_id' => $teacher->id,
        ]);

        // 前面幾個學生沒有帳號，最後一個連結登入帳號（負責填點名單那位）。
        Student::factory()->forClass($class)->count(7)->create();

        $repUser = User::factory()->create(['username' => $repUsername]);
        $repUser->assignRole('student_rep');

        Student::factory()->forClass($class)->create([
            'name' => $repUser->name,
            'user_id' => $repUser->id,
        ]);
    }
}
