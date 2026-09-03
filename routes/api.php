<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GeneratorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamenController;

Route::post('/generate', [GeneratorController::class, 'generate']);

Route::get('/projects', [ProjectController::class, 'index']);
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects/{id}/history', [ProjectController::class, 'history']);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function () {
        return auth()->user();
    });
});
Route::get('/examen', [ExamenController::class, 'index']);
Route::post('/examen', [ExamenController::class, 'store']);
Route::put('/examen/{id}', [ExamenController::class, 'update']);
Route::delete('/examen/{id}', [ExamenController::class, 'destroy']);
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
Route::get('/dashboard/recent-orders', [DashboardController::class, 'recentOrders']);
Route::get('/dashboard/recent-products', [DashboardController::class, 'recentProducts']);
Route::get('/photos',[PhotoController::class,'index']);
Route::post('/photos',[PhotoController::class,'store']);
Route::put('/photos/{id}',[PhotoController::class,'update']);
Route::delete('/photos/{id}',[PhotoController::class,'destroy']);
Route::get('/profile',[ProfileController::class,'index']);
Route::post('/profile',[ProfileController::class,'store']);
Route::post('/profile/{id}',[ProfileController::class,'update']);
Route::delete('/profile/{id}',[ProfileController::class,'destroy']);
Route::post('/profile/calendar',[ProfileController::class,'storeCalendar']);