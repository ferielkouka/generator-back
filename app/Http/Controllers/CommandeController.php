<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Commande;

class CommandeController extends Controller
{
  public function store(Request $request)
  {
    $item = Commande::create($request->all());
    return response()->json($item, 201);
  }

  public function index()
  {
    return response()->json(Commande::all());
  }
}