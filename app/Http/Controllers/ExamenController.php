<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Examen;
use Illuminate\Support\Facades\Storage;

class ExamenController extends Controller
{
    public function index()
    {
        return response()->json(Examen::all());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'titre' => 'required|string|max:255',
            'sujet' => 'nullable|file|mimes:pdf|max:2048',
            'correction' => 'nullable|file|mimes:pdf|max:2048'
        ]);

        if ($request->hasFile('sujet')) {
            $data['sujet'] = $request->file('sujet')->store('uploads', 'public');
        }

        if ($request->hasFile('correction')) {
            $data['correction'] = $request->file('correction')->store('uploads', 'public');
        }

        $examen = Examen::create($data);
        return response()->json($examen, 201);
    }

    public function update(Request $request, $id)
    {
        $examen = Examen::findOrFail($id);

        $data = $request->validate([
            'titre' => 'sometimes|string|max:255',
            'sujet' => 'nullable|file|mimes:pdf|max:2048',
            'correction' => 'nullable|file|mimes:pdf|max:2048'
        ]);

        if ($request->hasFile('sujet')) {
            if ($examen->sujet) {
                Storage::disk('public')->delete($examen->sujet);
            }
            $data['sujet'] = $request->file('sujet')->store('uploads', 'public');
        }

        if ($request->hasFile('correction')) {
            if ($examen->correction) {
                Storage::disk('public')->delete($examen->correction);
            }
            $data['correction'] = $request->file('correction')->store('uploads', 'public');
        }

        $examen->update($data);
        return response()->json($examen);
    }

    public function destroy($id)
    {
        $examen = Examen::findOrFail($id);

        if ($examen->sujet) {
            Storage::disk('public')->delete($examen->sujet);
        }

        if ($examen->correction) {
            Storage::disk('public')->delete($examen->correction);
        }

        $examen->delete();
        return response()->json(['message' => 'Examen supprimé avec succès']);
    }
}