<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 一次成功的資料庫備份。由備份容器直接以 SQL 寫入，應用程式只讀不寫
 * ——見 database/migrations 的 create_backup_runs_table 說明。
 */
class BackupRun extends Model
{
    protected $fillable = ['completed_at', 'file_name', 'size_bytes'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
