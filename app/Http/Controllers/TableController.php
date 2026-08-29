<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Table;

class TableController extends Controller
{
  public function index()
  {
    return response()->json(Table::all());
  }

  public function store(Request $request)
  {
    $item = Table::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = Table::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    Table::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}