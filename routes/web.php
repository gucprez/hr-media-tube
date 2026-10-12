<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/perfil/password', [ProfileController::class, 'password'])->name('profile.password');

    // Super administrador (HR Media System): gestión de empresas / clientes SaaS.
    Route::prefix('plataforma')->middleware('superadmin')->name('admin.')->group(function () {
        Route::resource('empresas', CompanyController::class)->parameters(['empresas' => 'company'])->names('companies')->except(['show']);
        Route::post('empresas/{company}/entrar', [CompanyController::class, 'enter'])->name('companies.enter');
        Route::post('empresas/salir', [CompanyController::class, 'leave'])->name('companies.leave');
    });

    Route::middleware('company')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');

        Route::prefix('administracion')->name('admin.')->group(function () {
            Route::middleware('permission:users.manage')->group(function () {
                Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->names('users')->except(['show']);
            });
            Route::middleware('permission:roles.manage')->group(function () {
                Route::resource('roles', RoleController::class)->except(['show']);
            });
            Route::middleware('permission:audit.view')->get('auditoria', [AuditLogController::class, 'index'])->name('audit.index');

            Route::middleware('permission:settings.manage')->prefix('configuracion')->name('settings.')->group(function () {
                Route::get('/', [SettingsController::class, 'company'])->name('company');
                Route::put('/', [SettingsController::class, 'updateCompany'])->name('company.update');
                Route::get('sucursales', [SettingsController::class, 'branches'])->name('branches');
                Route::post('sucursales', [SettingsController::class, 'storeBranch'])->name('branches.store');
                Route::put('sucursales/{branch}', [SettingsController::class, 'updateBranch'])->name('branches.update');
                Route::delete('sucursales/{branch}', [SettingsController::class, 'destroyBranch'])->name('branches.destroy');
                Route::post('almacenes', [SettingsController::class, 'storeWarehouse'])->name('warehouses.store');
                Route::put('almacenes/{warehouse}', [SettingsController::class, 'updateWarehouse'])->name('warehouses.update');
                Route::delete('almacenes/{warehouse}', [SettingsController::class, 'destroyWarehouse'])->name('warehouses.destroy');
                Route::get('numeracion', [SettingsController::class, 'numbering'])->name('numbering');
                Route::put('numeracion', [SettingsController::class, 'updateNumbering'])->name('numbering.update');
            });
        });
    });
});
