<?php

namespace Tests\Feature\Support;

use App\Support\ClassCode;
use Tests\TestCase;

class ClassCodeTest extends TestCase
{
    public function test_a_single_digit_class_number_drops_its_leading_zero(): void
    {
        // 101 = 1年1班，不是 1年01班——資料庫裡 class_number 是不補零的
        // 整數，補零字串比對不到。
        $this->assertSame(['grade' => 1, 'class_number' => 1], ClassCode::parse('101'));
    }

    public function test_a_two_digit_class_number_is_parsed_whole(): void
    {
        $this->assertSame(['grade' => 2, 'class_number' => 11], ClassCode::parse('211'));
    }

    public function test_the_third_grade_is_accepted(): void
    {
        $this->assertSame(['grade' => 3, 'class_number' => 7], ClassCode::parse('307'));
    }

    public function test_surrounding_whitespace_is_ignored(): void
    {
        $this->assertSame(['grade' => 1, 'class_number' => 1], ClassCode::parse(' 101 '));
    }

    public function test_a_code_that_is_not_three_digits_is_rejected(): void
    {
        // 與其硬解析出一個可能是錯的年級/班級，不如直接讓匯入畫面標成
        // 錯誤讓人工確認。
        $this->assertNull(ClassCode::parse('10'));
        $this->assertNull(ClassCode::parse('1011'));
    }

    public function test_a_non_numeric_code_is_rejected(): void
    {
        $this->assertNull(ClassCode::parse('忠孝班'));
        $this->assertNull(ClassCode::parse('1a1'));
        $this->assertNull(ClassCode::parse(''));
    }

    public function test_a_grade_outside_one_to_three_is_rejected(): void
    {
        $this->assertNull(ClassCode::parse('401'));
        $this->assertNull(ClassCode::parse('001'));
    }

    public function test_a_zero_class_number_is_rejected(): void
    {
        $this->assertNull(ClassCode::parse('100'));
    }
}
