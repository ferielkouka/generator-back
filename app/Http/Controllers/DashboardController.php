<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Order;
use App\Models\User;

class DashboardController extends Controller
{
    public function stats()
    {
        $stats = [
            'totalProducts' => Product::count(),
            'totalOrders' => Order::count(),
            'totalUsers' => User::count(),
            'totalRevenue' => Order::sum('total')
        ];

        return response()->json($stats);
    }

    public function recentOrders()
    {
        $orders = Order::with('user')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get(['id', 'user_id', 'total', 'status', 'created_at'])
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'customerName' => $order->user->name,
                    'total' => $order->total,
                    'status' => $order->status,
                    'date' => $order->created_at
                ];
            });

        return response()->json($orders);
    }

    public function recentProducts()
    {
        $products = Product::orderBy('created_at', 'desc')
            ->take(5)
            ->get(['id', 'name', 'price', 'stock', 'image_url as imageUrl'])
            ->toArray();

        return response()->json($products);
    }
}