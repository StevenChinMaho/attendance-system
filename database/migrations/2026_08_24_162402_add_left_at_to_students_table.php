<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 學生轉學／畢業離校時，不能直接刪除這筆資料——attendance_records.
     * student_id 是 cascadeOnDelete，刪掉學生會連坐刪光他過去所有的
     * 點名紀錄。用 nullable 的 left_at 標記「已經不在讀了」，比單純的
     * boolean 多存一個「什麼時候離開的」的資訊，跟 users.last_login_at
     * 一樣的處理方式。
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
