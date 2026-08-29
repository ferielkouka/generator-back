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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'size' => 'required|in:S,M,L,XL,XXL',
            'price' => 'required|numeric|min:0'
        ]);

        $article = Article::create($validated);
        return response()->json($article, 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'size' => 'required|in:S,M,L,XL,XXL',
            'price' => 'required|numeric|min:0'
        ]);

        $article = Article::findOrFail($id);
        $article->update($validated);
        return response()->json($article);
    }

    public function destroy($id)
    {
        Article::destroy($id);
        return response()->json(['message' => 'Article supprimé avec succès']);
    }
}