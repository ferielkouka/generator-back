<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Note;

class NoteController extends Controller
{
  public function index()
  {
    return response()->json(Note::all());
  }

  public function store(Request $request)
  {
    $item = Note::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Note::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Note::destroy($id);
    return response()->json(['message' => 'Note supprimée avec succès']);
  }
}