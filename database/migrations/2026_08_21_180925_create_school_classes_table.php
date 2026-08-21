<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('academic_year'); // 民國年整數，例如 113
            $table->unsignedTinyInteger('semester');
            $table->unsignedTinyInteger('grade');
            // string 而非整數：保留容納非數字班級代號（例如忠孝仁愛）的彈性。
            $table->string('class_number');
            // nullable：新學年剛開學、還沒指派導師的過渡狀態允許存在。
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->timestamps();

            // 每個學年度每學期不會有兩個一樣年級+班級代號的班級。
            $table->unique(['academic_year', 'semester', 'grade', 'class_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
