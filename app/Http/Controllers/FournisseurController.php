<?php
namespace App\\Http\\Controllers;
use Illuminate\\Http\\Request;
use App\\Models\\Fournisseur;

class FournisseurController extends Controller
{
  public function index()
  {
    return response()->json(Fournisseur::all());
  }

  public function store(Request $request)
  {
    $item = Fournisseur::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Fournisseur::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Fournisseur::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}