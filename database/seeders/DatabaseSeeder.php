<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        User::factory()->create([
            'name' => 'Test Admin',
            'username' => 'admin',
        ])->assignRole('admin');

        // 固定的示範班級／導師／學生帳號資料，只在本機開發／測試環境跑——
        // 正式環境的帳號一律由管理者在後台建立，不應該有已知密碼的帳號
        // 隨 migrate --seed 自動生出來。見 DemoDataSeeder 的說明。
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
