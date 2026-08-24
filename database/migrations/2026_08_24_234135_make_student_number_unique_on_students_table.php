<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 學號原本只在「同一個班級」內唯一——但同一個真實學生從入學到畢業
     * 自始至終只有一筆 students 資料（升學年是直接更新 school_class_id
     * 指向新班級，不會另外新增一筆，見 system_structure.md 學年制度），
     * 所以學號本來就該是這一筆資料的全校唯一身分，不是「這個班級裡面
     * 唯一」而已。座號維持班級內唯一不變——座號本來就是每班各自從頭
     * 排的，不需要全校唯一。
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['school_class_id', 'student_number']);
            $table->unique('student_number');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['student_number']);
            $table->unique(['school_class_id', 'student_number']);
        });
    }
};
