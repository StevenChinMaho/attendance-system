<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            // nullable + unique：只有副班長會有登入帳號。
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('student_number');
            $table->string('seat_number');
            $table->string('name');
            $table->string('gender');
            $table->timestamps();

            // 學號/座號在「同一個班級」內不能重複，但跨班級/跨學年本來就是
            // 獨立的班級紀錄，不需要全校唯一（見 system_structure.md 學年制度）。
            $table->unique(['school_class_id', 'student_number']);
            $table->unique(['school_class_id', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
