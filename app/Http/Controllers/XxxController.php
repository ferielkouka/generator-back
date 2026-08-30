<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Xxx;

class XxxController extends Controller
{
  public function index()
  {
    return response()->json(Xxx::all());
  }

  public function store(Request $request)
  {
    $item = Xxx::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Xxx::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Xxx::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}