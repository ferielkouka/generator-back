<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $stats = [
            'totalSales' => DB::table('orders')->count(),
            'totalRevenue' => DB::table('orders')->sum('total'),
            'totalOrders' => DB::table('orders')->count(),
            'totalProducts' => DB::table('products')->count(),
            'totalCustomers' => DB::table('users')->where('role', 'customer')->count(),
            'pendingOrders' => DB::table('orders')->where('status', 'pending')->count()
        ];

        return response()->json($stats);
    }
}