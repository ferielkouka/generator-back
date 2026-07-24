<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Article;

class ArticleController extends Controller
{
  public function store(Request $request)
  {
    $article = Article::create($request->all());
    return response()->json($article, 201);
  }

  public function index()
  {
    return response()->json(Article::all());
  }
}