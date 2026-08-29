<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GeneratorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\EnseignantController;
use App\Http\Controllers\StatistiquesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\FilmController;
use App\Http\Controllers\EtudiantController;
use App\Http\Controllers\LivreController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\CommandeController;
use App\Http\Controllers\CommentairesController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfilController;

Route::post('/generate', [GeneratorController::class, 'generate']);
Route::get('/projects', [ProjectController::class, 'index']);
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects/{id}/history', [ProjectController::class, 'history']);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/products', [ProductController::class, 'store']);
Route::get('/products', [ProductController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function () {
        return auth()->user();
    });
});

Route::post('/blog',[BlogController::class,'store']);
Route::get('/blog',[BlogController::class,'index']);
Route::post('/contacts',[ContactsController::class,'store']);
Route::get('/contacts',[ContactsController::class,'index']);
Route::post('/tasks',[TasksController::class,'store']);
Route::get('/tasks',[TasksController::class,'index']);
Route::post('/commentaires',[CommentairesController::class,'store']);
Route::get('/commentaires',[CommentairesController::class,'index']);
Route::post('/commande',[CommandeController::class,'store']);
Route::get('/commande',[CommandeController::class,'index']);
Route::get('/articles',[ArticleController::class,'index']);
Route::post('/articles',[ArticleController::class,'store']);
Route::post('/livres',[LivreController::class,'store']);
Route::get('/livres',[LivreController::class,'index']);
Route::post('/etudiant',[EtudiantController::class,'store']);
Route::get('/etudiant',[EtudiantController::class,'index']);
Route::put('/etudiant/{id}',[EtudiantController::class,'update']);
Route::delete('/etudiant/{id}',[EtudiantController::class,'destroy']);
Route::post('/film',[FilmController::class,'store']);
Route::get('/film',[FilmController::class,'index']);
Route::post('/article',[ArticleController::class,'store']);
Route::get('/article',[ArticleController::class,'index']);
Route::get('/stats',[StatsController::class,'stats']);
Route::get('/students/count', [DashboardController::class, 'countStudents']);
Route::get('/articles/count', [DashboardController::class, 'countArticles']);
Route::get('/contacts/count', [DashboardController::class, 'countContacts']);
Route::get('/students-count',[DashboardController::class,'studentsCount']);
Route::get('/articles-count',[DashboardController::class,'articlesCount']);
Route::get('/contacts-count',[DashboardController::class,'contactsCount']);
Route::get('/statistiques',[StatistiquesController::class,'index']);
Route::post('/livre',[LivreController::class,'store']);
Route::get('/livre',[LivreController::class,'index']);

Route::get('/profil', [ProfilController::class, 'getUser']);
Route::put('/profil', [ProfilController::class, 'updateUser']);

Route::post('/enseignants',[EnseignantController::class,'store']);
Route::get('/enseignants',[EnseignantController::class,'index']);
Route::put('/enseignants/{id}',[EnseignantController::class,'update']);
Route::delete('/enseignants/{id}',[EnseignantController::class,'destroy']);

Route::get('/notes',[NoteController::class,'index']);
Route::post('/notes',[NoteController::class,'store']);
Route::put('/notes/{id}',[NoteController::class,'update']);
Route::delete('/notes/{id}',[NoteController::class,'destroy']);
Route::get('/note', [NoteController::class, 'index']);
Route::post('/note', [NoteController::class, 'store']);
Route::put('/note/{id}', [NoteController::class, 'update']);
Route::delete('/note/{id}', [NoteController::class, 'destroy']);
Route::get('/tables',[TableController::class,'index']);
Route::post('/tables',[TableController::class,'store']);
Route::put('/tables/{id}',[TableController::class,'update']);
Route::delete('/tables/{id}',[TableController::class,'destroy']);
Route::get('/document',[DocumentController::class,'index']);
Route::post('/document',[DocumentController::class,'store']);
Route::put('/document/{id}',[DocumentController::class,'update']);
Route::delete('/document/{id}',[DocumentController::class,'destroy']);