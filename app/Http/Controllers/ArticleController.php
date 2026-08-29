<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Article;

class ArticleController extends Controller
{
  public function index()
  {
    return response()->json(Article::all());
  }

  public function store(Request $request)
  {
    $item = Article::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Article::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Article::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}