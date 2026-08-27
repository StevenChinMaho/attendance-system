<?php

namespace Tests\Feature\Models;

use App\Models\SchoolClass;
use App\Support\AcademicPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 班級的自然排列順序是「年級優先、再班級編號」。
     *
     * 這個 scope 以前叫 orderByClassNumber，而且真的只排了 class_number，
     * 結果是 1年1班／2年1班／3年1班 全部擠在最前面，接著才是所有 2 號班。
     * 畫面上看起來正常的那幾頁，是各自在呼叫端多寫了一句 orderBy('grade')
     * 補起來的——漏寫的地方就排錯（學生管理的班級篩選選單、導覽列的班級
     * 選單都中過）。年級已經移進 scope 裡，這個測試就是防止它被拿掉。
     */
    public function test_classes_are_ordered_by_grade_then_class_number(): void
    {
        // 刻意用亂序建立，而且讓「班級編號小、但年級大」的班排在前面
        // ——只排 class_number 的話 3年1班 會跑到 1年2班 前面。
        SchoolClass::factory()->create(['grade' => 3, 'class_number' => 1]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 2]);
        SchoolClass::factory()->create(['grade' => 2, 'class_number' => 1]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 1]);
        SchoolClass::factory()->create(['grade' => 2, 'class_number' => 10]);

        $ordered = SchoolClass::orderByClassCode()->get()
            ->map(fn (SchoolClass $class) => $class->shortLabel())
            ->all();

        $this->assertSame(
            ['1年1班', '1年2班', '2年1班', '2年10班', '3年1班'],
            $ordered,
        );
    }

    /**
     * class_number 是整數欄位，所以 10 排在 2 後面而不是「字典序」的 1、10、2
     * ——上面那個測試已經涵蓋（2年10班 在 2年1班 之後），這裡單獨再釘一次
     * 純數字的部分，避免有人把欄位改回字串時沒察覺。
     */
    public function test_class_numbers_sort_numerically_not_lexically(): void
    {
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 10]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 2]);
        SchoolClass::factory()->create(['grade' => 1, 'class_number' => 1]);

        $ordered = SchoolClass::orderByClassCode()->pluck('class_number')->all();

        $this->assertSame([1, 2, 10], $ordered);
    }

    public function test_ordering_composes_with_an_academic_period_filter(): void
    {
        $currentYear = AcademicPeriod::currentYear();

        SchoolClass::factory()->create(['academic_year' => $currentYear, 'grade' => 2, 'class_number' => 1]);
        SchoolClass::factory()->create(['academic_year' => $currentYear, 'grade' => 1, 'class_number' => 3]);
        SchoolClass::factory()->create(['academic_year' => $currentYear - 1, 'grade' => 1, 'class_number' => 1]);

        $ordered = SchoolClass::where('academic_year', $currentYear)
            ->orderByClassCode()
            ->get()
            ->map(fn (SchoolClass $class) => $class->shortLabel())
            ->all();

        $this->assertSame(['1年3班', '2年1班'], $ordered);
    }
}
