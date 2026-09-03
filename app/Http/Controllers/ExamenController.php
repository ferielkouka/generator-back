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
        $validated = $request->validate([
            'titre' => 'required|string|min:3',
            'examen_pdf' => 'nullable|file|mimes:pdf|max:10240',
            'correction_pdf' => 'nullable|file|mimes:pdf|max:10240'
        ]);

        $data = ['titre' => $validated['titre']];

        if ($request->hasFile('examen_pdf')) {
            $data['examen_pdf'] = $request->file('examen_pdf')->store('uploads/examens', 'public');
        }

        if ($request->hasFile('correction_pdf')) {
            $data['correction_pdf'] = $request->file('correction_pdf')->store('uploads/corrections', 'public');
        }

        $examen = Examen::create($data);
        return response()->json($examen, 201);
    }

    public function update(Request $request, $id)
    {
        $examen = Examen::findOrFail($id);

        $validated = $request->validate([
            'titre' => 'required|string|min:3',
            'examen_pdf' => 'nullable|file|mimes:pdf|max:10240',
            'correction_pdf' => 'nullable|file|mimes:pdf|max:10240'
        ]);

        $examen->update(['titre' => $validated['titre']]);

        if ($request->hasFile('examen_pdf')) {
            if ($examen->examen_pdf) {
                Storage::disk('public')->delete($examen->examen_pdf);
            }
            $examen->update(['examen_pdf' => $request->file('examen_pdf')->store('uploads/examens', 'public')]);
        }

        if ($request->hasFile('correction_pdf')) {
            if ($examen->correction_pdf) {
                Storage::disk('public')->delete($examen->correction_pdf);
            }
            $examen->update(['correction_pdf' => $request->file('correction_pdf')->store('uploads/corrections', 'public')]);
        }

        return response()->json($examen);
    }

    public function destroy($id)
    {
        $examen = Examen::findOrFail($id);

        if ($examen->examen_pdf) {
            Storage::disk('public')->delete($examen->examen_pdf);
        }

        if ($examen->correction_pdf) {
            Storage::disk('public')->delete($examen->correction_pdf);
        }

        $examen->delete();
        return response()->json(['message' => 'Examen supprimé avec succès']);
    }
}