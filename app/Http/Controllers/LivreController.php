<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Livre;

class LivreController extends Controller
{
  public function store(Request $request)
  {
    $livre = Livre::create($request->all());
    return response()->json($livre, 201);
  }

  public function index()
  {
    return response()->json(Livre::all());
  }
}