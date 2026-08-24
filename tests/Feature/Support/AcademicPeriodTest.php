<?php

namespace Tests\Feature\Support;

use App\Models\SchoolClass;
use App\Support\AcademicPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AcademicPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_august_first_starts_a_new_academic_year_at_semester_one(): void
    {
        Carbon::setTestNow('2026-08-01');

        $this->assertSame(['year' => 115, 'semester' => 1], AcademicPeriod::forDate(now()));
    }

    public function test_july_thirty_first_is_still_semester_two_of_the_previous_academic_year(): void
    {
        Carbon::setTestNow('2026-07-31');

        $this->assertSame(['year' => 114, 'semester' => 2], AcademicPeriod::forDate(now()));
    }

    public function test_january_thirty_first_is_still_semester_one(): void
    {
        Carbon::setTestNow('2027-01-31');

        $this->assertSame(['year' => 115, 'semester' => 1], AcademicPeriod::forDate(now()));
    }

    public function test_february_first_switches_to_semester_two_of_the_same_academic_year(): void
    {
        Carbon::setTestNow('2027-02-01');

        $this->assertSame(['year' => 115, 'semester' => 2], AcademicPeriod::forDate(now()));
    }

    public function test_selected_period_defaults_to_the_current_one_until_explicitly_set(): void
    {
        Carbon::setTestNow('2026-08-22');

        $this->assertSame(115, AcademicPeriod::selectedYear());
        $this->assertSame(1, AcademicPeriod::selectedSemester());

        AcademicPeriod::setSelected(112, 2);

        $this->assertSame(112, AcademicPeriod::selectedYear());
        $this->assertSame(2, AcademicPeriod::selectedSemester());
    }

    public function test_year_options_range_from_the_oldest_class_on_record_to_ten_years_past_the_current_one(): void
    {
        Carbon::setTestNow('2026-08-22');

        SchoolClass::factory()->create(['academic_year' => 110]);

        $this->assertSame(range(110, 125), AcademicPeriod::yearOptions());
    }

    public function test_year_options_start_from_the_current_year_when_no_classes_exist_yet(): void
    {
        Carbon::setTestNow('2026-08-22');

        $this->assertSame(range(115, 125), AcademicPeriod::yearOptions());
    }

    public function test_is_selected_current_is_true_before_any_explicit_switch(): void
    {
        Carbon::setTestNow('2026-08-22');

        $this->assertTrue(AcademicPeriod::isSelectedCurrent());
    }

    public function test_is_selected_current_is_false_after_switching_to_a_different_period(): void
    {
        Carbon::setTestNow('2026-08-22');

        AcademicPeriod::setSelected(112, 2);

        $this->assertFalse(AcademicPeriod::isSelectedCurrent());
    }

    public function test_is_selected_current_is_true_again_after_switching_back(): void
    {
        Carbon::setTestNow('2026-08-22');

        AcademicPeriod::setSelected(112, 2);
        AcademicPeriod::setSelected(115, 1);

        $this->assertTrue(AcademicPeriod::isSelectedCurrent());
    }
}
