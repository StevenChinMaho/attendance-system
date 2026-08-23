<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 只影響管理者之後建立/重設密碼的帳號——預設 false，避免這個
     * migration 一跑，既有帳號（含 DemoDataSeeder 建立的示範帳號）
     * 全部被莫名其妙強制登出改密碼。管理者建帳號、重設密碼時都是
     * 「幫使用者代打一個對方不知道、之後也不會再用的密碼」，讓使用者
     * 自己首次登入後被強制換成只有自己知道的密碼，才是完整的流程。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
