<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Film;

class FilmController extends Controller
{
  public function store(Request $request)
  {
    $film = Film::create($request->all());
    return response()->json($film, 201);
  }

  public function index()
  {
    return response()->json(Film::all());
  }
}