<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Game;

class GameController extends Controller
{
  public function index()
  {
    return response()->json(Game::all());
  }

  public function store(Request $request)
  {
    $item = Game::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Game::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Game::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}