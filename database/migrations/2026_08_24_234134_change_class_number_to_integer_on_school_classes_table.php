<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * class_number 原本是 string，理由是「保留容納非數字班級代號（例如
     * 忠孝仁愛）的彈性」，但確認實務上班級代號一律是正整數，不需要這個
     * 彈性——用 DB::statement 直接 MODIFY 而不是 Schema::table(...)->change()，
     * 避免額外依賴 doctrine/dbal（這個專案目前沒有安裝）。
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE school_classes MODIFY class_number TINYINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school_classes MODIFY class_number VARCHAR(255) NOT NULL');
    }
};
