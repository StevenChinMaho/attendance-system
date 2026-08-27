<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 備份成功的心跳紀錄。
 *
 * 由備份容器（docker/production/backup.sh）在每次 dump 完成後直接以
 * SQL 寫入——它跑的是 mariadb client，不是 PHP，所以這張表刻意保持
 * 極簡：沒有外鍵、沒有 enum、欄位都是最基本的型別，用一句 INSERT 就
 * 能寫完。
 *
 * 存在的目的是讓應用程式回答「上一次成功備份是什麼時候」，進而在後台
 * 顯示過期警告（見 config/backup.php）。用資料庫心跳而不是讓 app 去讀
 * 備份目錄，是為了不讓應用程式跟「備份檔放在哪」這件事耦合——
 * BACKUP_PATH 會隨著換主機而改變。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            // 備份完成的時間。刻意跟 created_at 分開：這張表未來若加上
            // 「補登過去的備份」之類的用途，兩者的語意並不相同。
            $table->timestamp('completed_at')->index();

            $table->string('file_name');

            // 壓縮後的位元組數。突然變得極小通常代表 dump 失敗但檔案
            // 仍然產生了，是很有用的警訊。
            $table->unsignedBigInteger('size_bytes');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
