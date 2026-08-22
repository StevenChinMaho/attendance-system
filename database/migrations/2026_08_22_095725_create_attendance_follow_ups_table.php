<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            // system_structure.md 的 ERD 草稿原本寫 teacher_id，但管理者
            // 沒有 Teacher 資料，會沒辦法記錄是誰留下這筆——跟
            // attendance_sessions.recorded_by / attendance_records.updated_by
            // 一致，改成指向 users，誰登入誰負責。
            $table->foreignId('created_by')->constrained('users');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_follow_ups');
    }
};
