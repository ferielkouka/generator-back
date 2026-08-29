<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Offre;

class OffreController extends Controller
{
  public function index()
  {
    return response()->json(Offre::all());
  }

  public function store(Request $request)
  {
    $item = Offre::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Offre::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Offre::destroy($id);
    return response()->json(['message' => 'Offre supprimée avec succès']);
  }
}