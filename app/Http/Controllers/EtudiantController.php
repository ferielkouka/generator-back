<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Etudiant;

class EtudiantController extends Controller
{
  public function index()
  {
    return response()->json(Etudiant::all());
  }

  public function store(Request $request)
  {
    $item = Etudiant::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Etudiant::find($id);
    $item->update($request->all());
    return response()->json($item, 200);
  }

  public function destroy($id)
  {
    Etudiant::find($id)->delete();
    return response()->json(['message' => 'Item deleted'], 200);
  }
}