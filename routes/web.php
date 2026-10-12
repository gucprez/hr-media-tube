<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Inventory\MovementController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\ReportCenterController;
use App\Http\Controllers\SearchController;
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

        // ---- Búsqueda global ---------------------------------------------------
        Route::get('/buscar', [SearchController::class, 'index'])->middleware('permission:search.use')->name('search');

        // ---- Archivos privados (autorizados por permiso y empresa) -------------
        Route::get('/archivos/producto/{image}', [FileController::class, 'productImage'])->name('files.product-image');
        Route::get('/archivos/adjunto/{attachment}', [FileController::class, 'attachment'])->name('files.attachment');

        // ---- Almacén: productos y catálogos -------------------------------------
        Route::prefix('almacen')->group(function () {
            Route::middleware('permission:products.view')->group(function () {
                Route::get('productos', [ProductController::class, 'index'])->name('products.index');
                Route::get('categorias', [CategoryController::class, 'index'])->name('categories.index');
            });
            Route::middleware('permission:products.create')->group(function () {
                Route::get('productos/nuevo', [ProductController::class, 'create'])->name('products.create');
                Route::post('productos', [ProductController::class, 'store'])->name('products.store');
                Route::post('categorias', [CategoryController::class, 'store'])->name('categories.store');
                Route::post('unidades', [CategoryController::class, 'storeUnit'])->name('units.store');
            });
            Route::middleware('permission:products.view')->get('productos/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::middleware('permission:products.edit')->group(function () {
                Route::get('productos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');
                Route::put('productos/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::post('productos/{product}/fotos', [ProductController::class, 'storeImages'])->name('products.images.store');
                Route::delete('productos/{product}/fotos/{image}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');
                Route::post('productos/{product}/fotos/{image}/principal', [ProductController::class, 'primaryImage'])->name('products.images.primary');
                Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
                Route::put('unidades/{unit}', [CategoryController::class, 'updateUnit'])->name('units.update');
            });
            Route::middleware('permission:products.delete')->group(function () {
                Route::delete('productos/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
                Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
                Route::delete('unidades/{unit}', [CategoryController::class, 'destroyUnit'])->name('units.destroy');
            });
        });

        // ---- Inventario: existencias y movimientos --------------------------------
        Route::prefix('inventario')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
                Route::get('movimientos', [MovementController::class, 'index'])->name('movements.index');
                Route::get('movimientos/{movement}', [MovementController::class, 'show'])->name('movements.show');
            });
            Route::middleware('permission:inventory.entry,inventory.dispatch,inventory.adjust')->group(function () {
                Route::get('api/productos', [InventoryController::class, 'lookup'])->name('inventory.lookup');
                Route::post('api/vista-previa', [InventoryController::class, 'preview'])->name('inventory.preview');
            });
            Route::middleware('permission:inventory.entry')->group(function () {
                Route::get('entradas/nueva', [MovementController::class, 'createEntry'])->name('entries.create');
                Route::post('entradas', [MovementController::class, 'storeEntry'])->name('entries.store');
            });
            Route::middleware('permission:inventory.dispatch')->group(function () {
                Route::get('salidas/nueva', [MovementController::class, 'createExit'])->name('exits.create');
                Route::post('salidas', [MovementController::class, 'storeExit'])->name('exits.store');
            });
            Route::middleware('permission:inventory.adjust')->group(function () {
                Route::get('ajustes/nuevo', [MovementController::class, 'createAdjustment'])->name('adjustments.create');
                Route::post('ajustes', [MovementController::class, 'storeAdjustment'])->name('adjustments.store');
            });
        });

        // ---- Centro de reportes ------------------------------------------------------
        Route::prefix('reportes')->middleware('permission:reports.view,reports.inventory.view,reports.fuel.view,reports.containers.view,reports.vehicles.view,reports.maintenance.view,reports.tires.view,reports.returnables.view,reports.audit.view')->group(function () {
            Route::get('/', [ReportCenterController::class, 'index'])->name('reports.index');
            Route::middleware('permission:reports.inventory.view')->prefix('almacen')->group(function () {
                Route::get('/', [ReportCenterController::class, 'warehouse'])->name('reports.warehouse');
                Route::get('inventario-actual', [InventoryReportController::class, 'current'])->name('reports.inventory');
                Route::get('movimiento-inventario', [InventoryReportController::class, 'movement'])->name('reports.inventory-movement');
            });
            Route::get('{section}', [ReportCenterController::class, 'section'])->where('section', 'fuel|containers|vehicles|maintenance|tires|returnables|audit')->name('reports.section');
        });

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
