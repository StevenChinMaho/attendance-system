<?php

use App\Http\Controllers\Admin\ShowClassStudentsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\GoToMyClassAttendanceController;
use App\Http\Controllers\ShowAttendanceController;
use App\Http\Controllers\ShowDashboardController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasChangedPassword;
use Illuminate\Support\Facades\Route;

// 未登入者永遠只會看到登入頁面，不存在任何會外洩資訊的公開路由。
Route::middleware('guest')->group(function () {
    Route::get('/', [LoginController::class, 'create'])->name('login');

    // 只有 POST /login 存在時，手動在網址列輸入 /login（很多人會這樣
    // 猜）會命中這個路徑但方法不符，得到 405 而不是登入頁——額外提供
    // GET /login 顯示同一個登入表單，避免使用者以為系統壞了。/ 仍是
    // 唯一具名的 login 路由，其他程式碼裡的 route('login') 不受影響。
    Route::get('/login', [LoginController::class, 'create']);
});

// POST /login 故意不掛 guest：如果瀏覽器透過「上一頁」或書籤顯示出登入頁
// 的過期快取，此時目前這個瀏覽器分頁其實已經是別人登入的 session
// （例如 user1），guest middleware 會在請求進到 LoginController 之前就
// 直接攔截、靜默導回 dashboard，讓提交 user2 帳密的請求整個被丟棄、
// 畫面上也不會有任何錯誤訊息——使用者會誤以為「登入 user2 沒有生效」。
// 讓這個路由不受 guest 限制，由 LoginController::store() 自己正確處理
// 「目前若已登入其他帳號，先登出再登入新帳號」。
Route::post('/login', [LoginController::class, 'store']);

Route::middleware(['auth', EnsureAccountIsActive::class, EnsureUserHasChangedPassword::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // 每個帳號自己變更密碼的地方，也是 EnsureUserHasChangedPassword
    // 把「必須先改密碼」的帳號導去的目的地——跟 logout 一樣要排除在
    // 這個 middleware 的檢查範圍之外，不然會卡進無限迴圈，見該
    // middleware 的說明。
    Route::view('/account/password', 'account.password')->name('account.password');

    // 導師/管理者看即時點名看板，副班長看簡單歡迎頁——見
    // ShowDashboardController，決定畫在哪個 view 的邏輯放在 controller。
    Route::get('/dashboard', ShowDashboardController::class)->name('dashboard');

    Route::get('/attendance', GoToMyClassAttendanceController::class)->name('attendance.mine');

    // can:recordAttendance,schoolClass 走 SchoolClassPolicy：admin 一定
    // 可以，副班長/導師僅限自己的班級——見 app/Policies/SchoolClassPolicy.php。
    Route::get('/attendance/{schoolClass}', ShowAttendanceController::class)
        ->middleware('can:recordAttendance,schoolClass')
        ->name('attendance.show');

    // 每個 admin 頁面各自掛一個對應的頁面級 permission（見
    // database/seeders/RolePermissionSeeder.php），不再統一用
    // role:admin 卡在同一個群組——這樣管理者才能透過 /admin/roles
    // （App\Livewire\Admin\RoleManager）新增自訂角色，只開放特定幾個
    // 頁面給特定帳號，而不是「要嘛是 admin 全部都能進、要嘛什麼都不能
    // 進」。用 Laravel 原生的 can: middleware（底層是
    // Illuminate\Auth\Middleware\Authorize）而不是 spatie 自帶的
    // role/permission middleware，是因為前者剛好在 Livewire 的
    // PersistentMiddleware allowlist 裡，之後每一次 wire:click 都會
    // 重新檢查一次，不是只在整頁載入那一刻檢查——見
    // App\Livewire\Concerns\RequiresPermission 的說明。
    Route::view('/admin/users', 'admin.users')->middleware('can:users.manage')->name('admin.users');
    Route::view('/admin/teachers', 'admin.teachers')->middleware('can:teachers.manage')->name('admin.teachers');
    Route::view('/admin/classes', 'admin.classes')->middleware('can:classes.manage')->name('admin.classes');
    Route::view('/admin/roles', 'admin.roles')->middleware('can:roles.manage')->name('admin.roles');

    // 學生本體（學號/姓名/性別/帳號連結/轉出狀態）跟「這個學生屬於哪個
    // 班」是兩個不同層次的動作（見 App\Livewire\Admin\StudentManager
    // 開頭的說明），所以是兩個獨立頁面：這裡是全校學生管理，不掛在
    // 某個班級底下。
    Route::view('/admin/students', 'admin.students')->middleware('can:students.manage')->name('admin.students');

    // Route::view 不支援隱含路由模型綁定；closure route 又無法被
    // route:cache 序列化，正式環境跑 optimize 會直接炸掉，所以用
    // invokable controller 兩邊都顧到。這個路由現在指向「班級名單」
    // （加入/移出既有學生），不是學生本體的建立/編輯。
    Route::get('/admin/classes/{schoolClass}/students', ShowClassStudentsController::class)
        ->middleware('can:students.manage')
        ->name('admin.classes.students');
});
