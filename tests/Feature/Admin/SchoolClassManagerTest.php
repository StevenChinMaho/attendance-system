<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\SchoolClassManager;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
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

    public function test_admin_can_create_a_class(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('academicYear', '113')
            ->set('semester', '1')
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
        SchoolClass::factory()->create([
            'academic_year' => 113, 'semester' => 1, 'grade' => 1, 'class_number' => '3',
        ]);

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('academicYear', '113')
            ->set('semester', '1')
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

        Livewire::actingAs($this->admin())
            ->test(SchoolClassManager::class)
            ->set('academicYear', '113')
            ->set('semester', '1')
            ->set('grade', '1')
            ->set('classNumber', '3')
            ->call('createClass')
            ->assertHasNoErrors();
    }

    public function test_classes_are_listed_in_natural_numeric_order_not_string_order(): void
    {
        SchoolClass::factory()->create(['academic_year' => 113, 'grade' => 1, 'class_number' => '10']);
        SchoolClass::factory()->create(['academic_year' => 113, 'grade' => 1, 'class_number' => '2']);

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
}
