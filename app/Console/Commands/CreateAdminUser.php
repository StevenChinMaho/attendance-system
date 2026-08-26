<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * 正式環境建立第一個管理者帳號的唯一管道。
 *
 * 為什麼需要這個指令，而不是沿用 seeder：DatabaseSeeder 裡那個
 * `User::factory()->create(['username' => 'admin'])` 是給本機開發用的，
 * UserFactory 的密碼寫死是 `password`，而且它沒有像 DemoDataSeeder 那樣
 * 的 local/testing 環境防護——在正式環境跑 `db:seed` 會直接生出一個
 * 全權管理員、密碼是全世界都知道的字串。（順帶一提 factory 依賴
 * fakerphp/faker，那在 require-dev，正式映像用 --no-dev 安裝的話這行
 * 反而會直接 fatal——也就是說現況不管走哪條路都是錯的。）
 *
 * 所以正式環境的初始化流程刻意拆成兩步，見 DEPLOYMENT.md：
 *   1. `db:seed --class=RolePermissionSeeder`（只建角色與權限，無帳號）
 *   2. `admin:create`（密碼由部署者當場輸入，不落在指令參數或環境變數裡）
 *
 * 密碼一律不接受從參數傳入：那會留在 shell history 跟 `ps` 的輸出裡。
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--username= : 登入帳號（未指定時會互動詢問）}
                            {--name= : 顯示用姓名（未指定時會互動詢問）}';

    protected $description = '建立一個擁有 admin 身分的管理者帳號（密碼互動輸入，首次登入強制變更）';

    public function handle(): int
    {
        // 角色是 RolePermissionSeeder 建的。先擋在這裡給出明確訊息，
        // 不然 assignRole() 會丟出 RoleDoesNotExist，訊息對「照著部署
        // 文件操作的人」來說難以對應到「你少跑了上一步」。
        if (! Role::where('name', 'admin')->where('guard_name', 'web')->exists()) {
            $this->error('找不到 admin 身分，請先執行：php artisan db:seed --class=RolePermissionSeeder');

            return self::FAILURE;
        }

        $username = $this->option('username') ?: $this->ask('登入帳號');
        $name = $this->option('name') ?: $this->ask('顯示用姓名');

        $password = $this->secret('密碼（至少 8 個字元，輸入時不會顯示）');
        $confirmation = $this->secret('再輸入一次密碼');

        $validator = Validator::make([
            'username' => $username,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            // 跟 UserManager::createUser() 用同一組規則，避免這條路徑
            // 建出來的帳號比後台建的還寬鬆。
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'username.unique' => '這個帳號已經存在。',
            'password.confirmed' => '兩次輸入的密碼不一致。',
            'password.min' => '密碼至少要 8 個字元。',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        // must_change_password 不在 User 的 #[Fillable] 清單裡（刻意的，
        // 見 User 開頭的說明），只能 forceFill。這裡設成 true 的理由跟
        // UserManager::createUser() 一樣：密碼是「別人幫你設的」，帳號
        // 本人應該要有機會換成只有自己知道的。
        $user->forceFill(['must_change_password' => true])->save();

        $user->assignRole('admin');

        $this->info("管理者帳號 {$user->username} 建立完成，首次登入時會要求變更密碼。");

        return self::SUCCESS;
    }
}
