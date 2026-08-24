<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 學生跟班級改成多對多——一個真實學生一輩子只有一筆 students 資料，
     * 但他在校期間會歷經好幾個班級（每學期一個，見 system_structure.md
     * 學年制度），這件事本來就是「連到哪幾筆 school_classes」而已，不需要
     * 額外記錄日期區間：每一筆 school_classes 本身就已經綁定特定學年度／
     * 學期，時間資訊已經包含在那筆紀錄裡了。
     *
     * 座號一併搬到這張表——座號是「這個學生在這個班的座號」，同一個
     * 學生在不同班級座號本來就可能不一樣，不該留在 students 表上。
     */
    public function up(): void
    {
        Schema::create('school_class_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('seat_number');
            $table->timestamps();

            // 同一個學生不會在同一個班級裡出現兩筆連結；同一個班級裡座號
            // 不能重複（跟原本 students 表上的 unique 限制語意相同，只是
            // 搬了位置）。
            $table->unique(['school_class_id', 'student_id']);
            $table->unique(['school_class_id', 'seat_number']);
        });

        // 把現有 students.school_class_id/seat_number 搬進新表——現階段
        // 每個學生本來就只連到一個班級，一對一搬過去不會遺失資料。
        DB::table('students')->select('id', 'school_class_id', 'seat_number')->orderBy('id')
            ->each(function (object $student) {
                DB::table('school_class_student')->insert([
                    'school_class_id' => $student->school_class_id,
                    'student_id' => $student->id,
                    'seat_number' => $student->seat_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['school_class_id']);
            $table->dropUnique(['school_class_id', 'seat_number']);
            $table->dropColumn(['school_class_id', 'seat_number']);
        });
    }

    /**
     * 盡力還原——如果某個學生此時已經連到不只一個班級（up() 之後才會
     * 發生的情況），只還原「最後一筆」連結，其餘連結會遺失。跟
     * student_departures migration 的 down() 是同一種「盡力而為、
     * 不保證完整還原」立場。
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('school_class_id')->nullable()->after('id')->constrained('school_classes')->cascadeOnDelete();
            $table->string('seat_number')->nullable()->after('school_class_id');
        });

        DB::table('school_class_student')->orderBy('id')->get()
            ->groupBy('student_id')
            ->each(function ($links) {
                $latest = $links->last();

                DB::table('students')->where('id', $latest->student_id)->update([
                    'school_class_id' => $latest->school_class_id,
                    'seat_number' => $latest->seat_number,
                ]);
            });

        // 不特地把兩欄改回 NOT NULL——down() 只是盡力還原，這裡改型別要
        // 額外裝 doctrine/dbal（這個專案沒裝），不值得為了 down() 這種
        // 很少真的會被執行的路徑增加依賴。
        Schema::table('students', function (Blueprint $table) {
            $table->unique(['school_class_id', 'seat_number']);
        });

        Schema::dropIfExists('school_class_student');
    }
};
