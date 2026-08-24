<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 學生轉出/轉入可能不只發生一次（確認過：轉出又轉入是真的會發生的
     * 情況，多次發生機率低但不保證不會發生）——原本 students.left_at
     * 只是單一一個「目前最新一次」的時間戳記，第二次轉出會直接覆蓋掉
     * 第一次的紀錄，把中間那段「其實已經轉入」的空窗期悄悄抹掉，導致
     * 回頭補登/查看那段期間的點名時判斷錯誤。改成獨立的一張表，一段
     * 「轉出到轉入」算一筆，可以有好幾筆，互不覆蓋。
     *
     * returned_at 為 null 代表「還沒轉回來」，等同於原本
     * students.left_at 不是 null 的狀態。
     */
    public function up(): void
    {
        Schema::create('student_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('left_at');
            $table->date('returned_at')->nullable();
            $table->timestamps();
        });

        // 既有資料搬過去：students.left_at 不是 null 的，代表「目前正在
        // 轉出中」，搬成一筆 returned_at 還是 null 的開放期間，不遺失
        // 現有狀態。
        DB::table('students')
            ->whereNotNull('left_at')
            ->get(['id', 'left_at'])
            ->each(function ($student) {
                DB::table('student_departures')->insert([
                    'student_id' => $student->id,
                    'left_at' => Carbon::parse($student->left_at)->toDateString(),
                    'returned_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('gender');
        });

        // 只還原「目前還沒轉回來」的那筆——舊的 schema 本來就只裝得下
        // 一筆狀態，已經結束（有 returned_at）的歷史期間沒有對應欄位
        // 可以還原，down() 只能盡力而為。
        DB::table('student_departures')
            ->whereNull('returned_at')
            ->get(['student_id', 'left_at'])
            ->each(function ($departure) {
                DB::table('students')
                    ->where('id', $departure->student_id)
                    ->update(['left_at' => $departure->left_at]);
            });

        Schema::dropIfExists('student_departures');
    }
};
