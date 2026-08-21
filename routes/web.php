<?php

use App\Http\Controllers\Admin\ShowClassStudentsController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// 未登入者永遠只會看到登入頁面，不存在任何會外洩資訊的公開路由。
Route::middleware('guest')->group(function () {
    Route::get('/', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // 暫時的登入後首頁，後續會換成依角色顯示的即時狀態看板。
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::middleware('role:admin')->group(function () {
        Route::view('/admin/users', 'admin.users')->name('admin.users');
        Route::view('/admin/teachers', 'admin.teachers')->name('admin.teachers');
        Route::view('/admin/classes', 'admin.classes')->name('admin.classes');

        // Route::view 不支援隱含路由模型綁定；closure route 又無法被
        // route:cache 序列化，正式環境跑 optimize 會直接炸掉，所以用
        // invokable controller 兩邊都顧到。
        Route::get('/admin/classes/{schoolClass}/students', ShowClassStudentsController::class)
            ->name('admin.classes.students');
    });
});
