<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
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
 * 班級／導師很花時間，這個 seeder 固定建立一組可預期、可以重複登入
 * 測試的資料，取代每次都要手動重建一輪。班級固定建在「目前」學年度／
 * 學期（見 App\Support\AcademicPeriod），這樣預設的學年度篩選一開啟
 * 就能直接看到，不用先切換導覽列。
 *
 * 刻意不建立任何學生：學生現在有正式的批量匯入管道（`/admin/students/import`
 * 吃學校的全校 Excel 匯出檔），空班級才是拿來測那條流程的正確起點；
 * 塞一批假學生進去反而每次都要先清掉。要手動測點名的話，先匯入或在
 * 「學生管理」建幾筆、再到班級的「管理學生」加進班級即可。
 *
 * 帳號密碼統一是 UserFactory 預設的 "password"（跟 DatabaseSeeder 建立
 * 的 admin 帳號一致），使用者名稱固定、方便每次重建後直接登入：
 * - teacher1／teacher2：導師（各帶一班）
 * - rep1／rep2：學生身分的帳號，**尚未連結任何學生資料**——因為沒有
 *   學生可以連。這兩個帳號登入後名下不會有任何班級（見
 *   User::ownSchoolClasses()），導覽列不會出現「點名」入口，要先建立
 *   學生並在「學生管理」把帳號連結上去才能點名。
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicPeriod::currentYear();
        $semester = AcademicPeriod::currentSemester();

        $this->createClassWithTeacher(
            year: $year,
            semester: $semester,
            grade: 1,
            classNumber: 1,
            teacherUsername: 'teacher1',
            teacherName: '王小明',
        );

        $this->createClassWithTeacher(
            year: $year,
            semester: $semester,
            grade: 2,
            classNumber: 3,
            teacherUsername: 'teacher2',
            teacherName: '陳雅婷',
        );

        // 學生身分的帳號留著（方便測權限差異），但沒有學生資料可以連結。
        foreach (['rep1', 'rep2'] as $repUsername) {
            User::factory()->create(['username' => $repUsername])->assignRole('student_rep');
        }
    }

    private function createClassWithTeacher(
        int $year,
        int $semester,
        int $grade,
        int $classNumber,
        string $teacherUsername,
        string $teacherName,
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

        SchoolClass::factory()->create([
            'academic_year' => $year,
            'semester' => $semester,
            'grade' => $grade,
            'class_number' => $classNumber,
            'homeroom_teacher_id' => $teacher->id,
        ]);
    }
}
