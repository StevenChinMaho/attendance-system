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
        // 角色與權限本身沒有任何帳號或密碼，正式環境也必須要有，
        // 所以留在環境判斷之外——這是 DEPLOYMENT.md 初始化步驟裡
        // 唯一會在正式環境執行的 seeder。
        $this->call(RolePermissionSeeder::class);

        // 以下都是「有已知密碼的帳號」，一律只在本機開發／測試環境跑。
        //
        // admin 帳號原本在這個判斷式「外面」，是一個實際的安全漏洞：
        // UserFactory 的密碼寫死是 password，正式環境只要跑過一次
        // `db:seed` 就會生出一個帳號 admin／密碼 password 的全權管理員，
        // 而且不像 DemoDataSeeder 有環境防護。（另一個角度是 factory
        // 依賴 fakerphp/faker，那在 require-dev，正式映像 --no-dev
        // 安裝時這行會直接 fatal——不管哪一種結果都是錯的。）
        // 正式環境改用 `php artisan admin:create` 手動建立，密碼當場輸入，
        // 見 App\Console\Commands\CreateAdminUser。
        if (app()->environment(['local', 'testing'])) {
            User::factory()->create([
                'name' => 'Test Admin',
                'username' => 'admin',
            ])->assignRole('admin');

            // 固定的示範班級／導師／學生帳號資料，見 DemoDataSeeder 的說明。
            $this->call(DemoDataSeeder::class);
        }
    }
}
