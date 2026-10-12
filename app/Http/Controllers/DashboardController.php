<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementItem as Line;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\AuditLog;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockQuery;
use App\Services\Inventory\StockStatus;

class DashboardController extends Controller
{
    public function index(StockQuery $stock, StockStatus $status, QuantityConverter $converter)
    {
        $user = auth()->user();
        $data = ['converter' => $converter, 'status' => $status];

        if ($user->hasPermission('products.view') || $user->hasPermission('inventory.view')) {
            $monthStart = now()->startOfMonth();
            $count = fn (string $s) => $stock->products(['active' => 'active', 'status' => $s])->count();
            $low = $count(StockStatus::LOW);
            $critical = $count(StockStatus::CRITICAL);

            $monthDocs = fn (string $type) => InventoryMovement::where('type', $type)->where('occurred_at', '>=', $monthStart);

            $data['inventory'] = [
                'products' => Product::where('is_active', true)->count(),
                'units' => (float) InventoryStock::sum('quantity_base'),
                'low' => $low + $critical,
                'critical' => $critical,
                'out' => $count(StockStatus::OUT),
                'entries_docs' => $monthDocs('entry')->count(),
                'entries_units' => (float) Line::where('type', Line::ENTRY)->where('occurred_at', '>=', $monthStart)->sum('delta_base'),
                'exits_docs' => $monthDocs('exit')->count(),
                'exits_units' => abs((float) Line::where('type', Line::EXIT)->where('occurred_at', '>=', $monthStart)->sum('delta_base')),
            ];

            // Alertas: primero agotados, luego críticos, luego bajos.
            $data['alerts'] = $stock->products(['active' => 'active'])->get()
                ->map(fn (Product $p) => ['product' => $p, 'status' => $p->status()])
                ->filter(fn ($a) => $a['status'] !== StockStatus::AVAILABLE)
                ->sortBy(fn ($a) => array_search($a['status'], [StockStatus::OUT, StockStatus::CRITICAL, StockStatus::LOW]))
                ->take(8)->values();

            $data['recent'] = InventoryMovement::with(['user', 'warehouse'])->withCount('items')
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get();
        }

        if ($user->hasPermission('containers.view')) {
            $monthStart = now()->startOfMonth()->toDateString();
            $data['containers'] = [
                'month' => \App\Models\Container::where('status', '!=', 'voided')->whereDate('date', '>=', $monthStart)->count(),
                'open' => \App\Models\Container::whereIn('status', ['open', 'in_progress'])->count(),
            ];
            $data['recentContainers'] = \App\Models\Container::with('vehicle')->orderByDesc('date')->orderByDesc('id')->limit(6)->get();
        }

        if ($user->hasPermission('fuel.view')) {
            $tank = app(\App\Services\Fuel\FuelService::class)->tank();
            $monthStart = now()->startOfMonth()->toDateString();
            $data['fuel'] = [
                'reserve' => (float) $tank->current_gallons, 'percent' => $tank->percent,
                'month' => (float) \App\Models\FuelDispatch::whereNull('voided_at')->whereDate('dispatched_at', '>=', $monthStart)->sum('gallons'),
            ];
            $data['recentDispatches'] = \App\Models\FuelDispatch::with('vehicle')->whereNull('voided_at')->orderByDesc('dispatched_at')->orderByDesc('id')->limit(6)->get();
        }

        if ($user->hasPermission('vehicles.view')) {
            $open = \App\Models\VehicleIncident::whereIn('status', \App\Models\VehicleIncident::OPEN);
            $monthAgo = now()->subMonth()->toDateString();
            $overdue = \App\Models\Vehicle::where('status', '!=', 'inactive')->whereDoesntHave('inspections', fn ($q) => $q->whereDate('inspected_at', '>', $monthAgo))->orderBy('ficha')->get(['id', 'ficha']);
            $data['fleet'] = [
                'operational' => \App\Models\Vehicle::where('status', 'operational')->count(),
                'total' => \App\Models\Vehicle::count(),
                'with_incidents' => (clone $open)->distinct('vehicle_id')->count('vehicle_id'),
                'pending_maintenance' => \App\Models\VehicleIncident::whereIn('status', ['pending', 'in_repair'])->count(),
                'pending_returns' => \App\Models\TireReturn::where('status', 'pending')->count() + app(\App\Services\Fleet\ReturnableService::class)->pending()->count(),
                'overdue' => $overdue,
                'critical' => (clone $open)->with('vehicle')->whereIn('priority', ['high', 'critical'])->orderByDesc('reported_at')->limit(5)->get(),
            ];
        }

        $data['audit'] = $user->hasPermission('audit.view') ? AuditLog::with('user')->latest('created_at')->latest('id')->limit(6)->get() : collect();

        return view('dashboard', $data);
    }
}
