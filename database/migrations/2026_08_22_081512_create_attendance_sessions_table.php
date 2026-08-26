<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->date('date');
            // string 而非資料庫層 enum：目前只會是 MORNING/NOON/AFTERNOON
            // 三個值（甲方一天三次點名的需求），但保留擴充為逐節點名
            // （1~8節）的彈性，不需要改資料表結構——見 system_structure.md。
            $table->string('period');
            // 誰送出這次點名（通常是學生，也可能是導師代為補登），供權責
            // 追溯用；跟「這個時段點名是否已完成」是兩件事，後者由這筆
            // session 存不存在直接判斷，不需要額外欄位。
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            // 同一個班級同一天同一個時段只會有一筆點名紀錄。
            $table->unique(['school_class_id', 'date', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
