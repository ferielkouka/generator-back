<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\TeamMember;

class TeamMemberController extends Controller
{
  public function index()
  {
    return response()->json(TeamMember::all());
  }

  public function store(Request $request)
  {
    $item = TeamMember::create($request->all());
    return response()->json($item, 201);
  }

  public function update(Request $request, $id)
  {
    $item = TeamMember::findOrFail($id);
    $item->update($request->all());
    return response()->json($item);
  }

  public function destroy($id)
  {
    TeamMember::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}