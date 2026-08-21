<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            // nullable + unique：只有需要登入的老師（導師、身兼管理者）才會有帳號。
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('teacher_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
