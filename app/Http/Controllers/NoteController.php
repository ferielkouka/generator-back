<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Note;

class NoteController extends Controller
{
    public function index()
    {
        return response()->json(Note::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string'
        ]);

        $note = Note::create($validated);
        return response()->json($note, 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string'
        ]);

        $note = Note::findOrFail($id);
        $note->update($validated);
        return response()->json($note);
    }

    public function destroy($id)
    {
        Note::destroy($id);
        return response()->json(['message' => 'Note supprimée avec succès']);
    }
}