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

        $data['audit'] = $user->hasPermission('audit.view') ? AuditLog::with('user')->latest('created_at')->latest('id')->limit(6)->get() : collect();

        return view('dashboard', $data);
    }
}
