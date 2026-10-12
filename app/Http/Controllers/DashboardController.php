<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return view('dashboard', [
            'stats' => [
                'users' => User::where('is_active', true)->count(),
                'branches' => Branch::where('is_active', true)->count(),
                'warehouses' => Warehouse::where('is_active', true)->count(),
            ],
            'recent' => $user->can('audit.view')
                ? AuditLog::with('user')->latest('created_at')->latest('id')->limit(8)->get()
                : collect(),
        ]);
    }
}
