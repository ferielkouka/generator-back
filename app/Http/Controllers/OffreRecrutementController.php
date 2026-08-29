<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\OffreRecrutement;

class OffreRecrutementController extends Controller
{
  public function index()
  {
    return response()->json(OffreRecrutement::all());
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'title' => 'required|string|max:255',
      'description' => 'required|string',
      'salary' => 'required|integer|min:0'
    ]);

    $item = OffreRecrutement::create($validated);
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $validated = $request->validate([
      'title' => 'required|string|max:255',
      'description' => 'required|string',
      'salary' => 'required|integer|min:0'
    ]);

    $item = OffreRecrutement::findOrFail($id);
    $item->update($validated);
    return response()->json($item);
  }

  public function destroy($id)
  {
    OffreRecrutement::destroy($id);
    return response()->json(['message' => 'Offre de recrutement supprimée avec succès']);
  }
}