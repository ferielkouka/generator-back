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
        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'role' => 'required|string|min:2',
            'email' => 'required|email'
        ]);

        $item = TeamMember::create($validated);
        return response()->json($item, 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'role' => 'required|string|min:2',
            'email' => 'required|email'
        ]);

        $item = TeamMember::findOrFail($id);
        $item->update($validated);
        return response()->json($item);
    }

    public function destroy($id)
    {
        TeamMember::destroy($id);
        return response()->json(['message' => 'Membre supprimé avec succès']);
    }
}