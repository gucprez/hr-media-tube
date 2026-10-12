<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Admin\ChecklistController;
use App\Http\Controllers\Fleet\DriverController;
use App\Http\Controllers\Fleet\IncidentController;
use App\Http\Controllers\Fleet\InspectionController;
use App\Http\Controllers\Fleet\MaintenanceController;
use App\Http\Controllers\Fleet\ReturnableController;
use App\Http\Controllers\Fleet\TireController;
use App\Http\Controllers\Fuel\FuelController;
use App\Http\Controllers\Fleet\VehicleController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Operations\ContainerController;
use App\Http\Controllers\Operations\TicketController;
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
        Route::get('/archivos/vehiculo/{vehicle}', [FileController::class, 'vehiclePhoto'])->name('files.vehicle-photo');
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

        // ---- Operaciones: furgones y tickets --------------------------------------
        Route::prefix('operaciones')->group(function () {
            Route::middleware('permission:containers.view')->group(function () {
                Route::get('furgones', [ContainerController::class, 'index'])->name('containers.index');
            });
            Route::middleware('permission:containers.create')->group(function () {
                Route::get('furgones/nuevo', [ContainerController::class, 'create'])->name('containers.create');
                Route::post('furgones', [ContainerController::class, 'store'])->name('containers.store');
                Route::get('furgones/{container}/editar', [ContainerController::class, 'edit'])->name('containers.edit');
                Route::put('furgones/{container}', [ContainerController::class, 'update'])->name('containers.update');
                Route::post('furgones/{container}/iniciar', [ContainerController::class, 'start'])->name('containers.start');
            });
            Route::middleware('permission:containers.view')->get('furgones/{container}', [ContainerController::class, 'show'])->name('containers.show');
            Route::middleware('permission:containers.close')->post('furgones/{container}/cerrar', [ContainerController::class, 'close'])->name('containers.close');
            Route::middleware('permission:containers.void')->post('furgones/{container}/anular', [ContainerController::class, 'void'])->name('containers.void');

            Route::middleware('permission:tickets.view')->group(function () {
                Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
            });
            Route::middleware('permission:tickets.create')->group(function () {
                Route::get('furgones/{container}/tickets/nuevo', [TicketController::class, 'create'])->name('tickets.create');
                Route::post('furgones/{container}/tickets', [TicketController::class, 'store'])->name('tickets.store');
            });
            Route::middleware('permission:tickets.view')->get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
            Route::middleware('permission:tickets.print')->group(function () {
                Route::get('tickets/{ticket}/imprimir', [TicketController::class, 'print'])->name('tickets.print');
                Route::get('tickets/{ticket}/pdf', [TicketController::class, 'pdf'])->name('tickets.pdf');
            });
            Route::middleware('permission:tickets.void')->post('tickets/{ticket}/anular', [TicketController::class, 'void'])->name('tickets.void');
        });

        // ---- Combustible ----------------------------------------------------------------
        Route::prefix('combustible')->group(function () {
            Route::middleware('permission:fuel.view')->group(function () {
                Route::get('/', [FuelController::class, 'index'])->name('fuel.index');
                Route::get('libro', [FuelController::class, 'ledger'])->name('fuel.ledger');
                Route::get('recepciones', [FuelController::class, 'receipts'])->name('fuel.receipts.index');
                Route::get('despachos', [FuelController::class, 'dispatches'])->name('fuel.dispatches.index');
            });
            Route::middleware('permission:fuel.receive')->group(function () {
                Route::get('recepciones/nueva', [FuelController::class, 'createReceipt'])->name('fuel.receipts.create');
                Route::post('recepciones', [FuelController::class, 'storeReceipt'])->name('fuel.receipts.store');
            });
            Route::middleware('permission:fuel.view')->get('recepciones/{receipt}', [FuelController::class, 'showReceipt'])->name('fuel.receipts.show');
            Route::middleware('permission:fuel.dispatch')->group(function () {
                Route::get('despachos/nuevo', [FuelController::class, 'createDispatch'])->name('fuel.dispatches.create');
                Route::post('despachos', [FuelController::class, 'storeDispatch'])->name('fuel.dispatches.store');
            });
            Route::middleware('permission:fuel.void')->group(function () {
                Route::post('recepciones/{receipt}/anular', [FuelController::class, 'voidReceipt'])->name('fuel.receipts.void');
                Route::post('despachos/{dispatch}/anular', [FuelController::class, 'voidDispatch'])->name('fuel.dispatches.void');
            });
            Route::middleware('permission:fuel.adjust')->group(function () {
                Route::get('ajuste', [FuelController::class, 'adjustForm'])->name('fuel.adjust');
                Route::post('ajuste', [FuelController::class, 'adjust'])->name('fuel.adjust.store');
                Route::put('tanque', [FuelController::class, 'updateTank'])->name('fuel.tank.update');
            });
        });

        // ---- Flotilla: vehículos y choferes ---------------------------------------
        Route::prefix('flotilla')->group(function () {
            Route::middleware('permission:vehicles.view')->group(function () {
                Route::get('vehiculos', [VehicleController::class, 'index'])->name('vehicles.index');
                Route::get('choferes', [DriverController::class, 'index'])->name('drivers.index');
            });
            Route::middleware('permission:vehicles.edit')->group(function () {
                Route::get('vehiculos/nuevo', [VehicleController::class, 'create'])->name('vehicles.create');
                Route::post('vehiculos', [VehicleController::class, 'store'])->name('vehicles.store');
                Route::get('vehiculos/{vehicle}/editar', [VehicleController::class, 'edit'])->name('vehicles.edit');
                Route::put('vehiculos/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
                Route::delete('vehiculos/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');
                Route::post('tipos-vehiculo', [VehicleController::class, 'storeType'])->name('vehicle-types.store');
                Route::post('choferes', [DriverController::class, 'store'])->name('drivers.store');
                Route::put('choferes/{driver}', [DriverController::class, 'update'])->name('drivers.update');
                Route::delete('choferes/{driver}', [DriverController::class, 'destroy'])->name('drivers.destroy');
            });
            Route::middleware('permission:vehicles.view')->get('vehiculos/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');

            // Inspecciones mensuales
            Route::middleware('permission:vehicles.view')->get('inspecciones', [InspectionController::class, 'index'])->name('inspections.index');
            Route::middleware('permission:vehicles.inspect')->group(function () {
                Route::get('inspecciones/nueva', [InspectionController::class, 'create'])->name('inspections.create');
                Route::post('inspecciones', [InspectionController::class, 'store'])->name('inspections.store');
            });
            Route::middleware('permission:vehicles.view')->get('inspecciones/{inspection}', [InspectionController::class, 'show'])->name('inspections.show');

            // Averías
            Route::middleware('permission:vehicles.view,incidents.report,incidents.manage')->get('averias', [IncidentController::class, 'index'])->name('incidents.index');
            Route::middleware('permission:incidents.report')->group(function () {
                Route::get('averias/nueva', [IncidentController::class, 'create'])->name('incidents.create');
                Route::post('averias', [IncidentController::class, 'store'])->name('incidents.store');
            });
            Route::middleware('permission:vehicles.view,incidents.report,incidents.manage')->get('averias/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
            Route::middleware('permission:incidents.manage')->post('averias/{incident}/estado', [IncidentController::class, 'status'])->name('incidents.status');

            // Mantenimiento
            Route::middleware('permission:maintenance.view')->get('mantenimiento', [MaintenanceController::class, 'index'])->name('maintenance.index');
            Route::middleware('permission:maintenance.manage')->group(function () {
                Route::get('mantenimiento/nuevo', [MaintenanceController::class, 'create'])->name('maintenance.create');
                Route::post('mantenimiento', [MaintenanceController::class, 'store'])->name('maintenance.store');
                Route::post('mantenimiento/{maintenance}/anular', [MaintenanceController::class, 'void'])->name('maintenance.void');
            });
            Route::middleware('permission:maintenance.view')->get('mantenimiento/{maintenance}', [MaintenanceController::class, 'show'])->name('maintenance.show');

            // Gomas nuevas y usadas
            Route::middleware('permission:tires.view')->get('gomas', [TireController::class, 'index'])->name('tires.index');
            Route::middleware('permission:tires.manage')->group(function () {
                Route::get('gomas/entrega', [TireController::class, 'create'])->name('tires.create');
                Route::post('gomas/entrega', [TireController::class, 'store'])->name('tires.store');
                Route::post('gomas/{tire}/recibir', [TireController::class, 'receive'])->name('tires.receive');
                Route::post('gomas/{tire}/cancelar', [TireController::class, 'waive'])->name('tires.waive');
            });

            // Retornables (tóner, etc.)
            Route::middleware('permission:returnables.view')->get('retornables', [ReturnableController::class, 'index'])->name('returnables.index');
            Route::middleware('permission:returnables.manage')->group(function () {
                Route::post('retornables/entregar', [ReturnableController::class, 'deliver'])->name('returnables.deliver');
                Route::post('retornables/estado', [ReturnableController::class, 'transition'])->name('returnables.transition');
            });
        });

        // ---- Inventario: existencias y movimientos --------------------------------
        Route::prefix('inventario')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
                Route::get('movimientos', [MovementController::class, 'index'])->name('movements.index');
                Route::get('movimientos/{movement}', [MovementController::class, 'show'])->name('movements.show');
            });
            Route::middleware('permission:inventory.void')->post('movimientos/{movement}/anular', [MovementController::class, 'void'])->name('movements.void');
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
            Route::get('{section}/{key}', [\App\Http\Controllers\Reports\ReportRunController::class, 'run'])->middleware('throttle:60,1')->where('section', 'warehouse|fuel|containers|vehicles|maintenance|tires|returnables|audit')->name('reports.run');
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
                Route::get('checklist', [ChecklistController::class, 'index'])->name('checklist');
                Route::post('checklist', [ChecklistController::class, 'store'])->name('checklist.store');
                Route::put('checklist/{item}', [ChecklistController::class, 'update'])->name('checklist.update');
                Route::delete('checklist/{item}', [ChecklistController::class, 'destroy'])->name('checklist.destroy');
                Route::post('tipos-vehiculo', [ChecklistController::class, 'storeType'])->name('vehicle-types.store');
                Route::delete('tipos-vehiculo/{type}', [ChecklistController::class, 'destroyType'])->name('vehicle-types.destroy');
                Route::get('numeracion', [SettingsController::class, 'numbering'])->name('numbering');
                Route::put('numeracion', [SettingsController::class, 'updateNumbering'])->name('numbering.update');
            });
        });
    });
});
