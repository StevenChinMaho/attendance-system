<?php

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
    });
});
