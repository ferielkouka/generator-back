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
        $request->validate([
            'titre' => 'required|string|max:255',
            'file_exam_pdf' => 'nullable|file|mimes:pdf|max:2048',
            'file_correction_pdf' => 'nullable|file|mimes:pdf|max:2048'
        ]);

        $data = $request->except('file_exam_pdf', 'file_correction_pdf');

        if ($request->hasFile('file_exam_pdf')) {
            $data['file_exam_pdf'] = $request->file('file_exam_pdf')->store('examens', 'public');
        }

        if ($request->hasFile('file_correction_pdf')) {
            $data['file_correction_pdf'] = $request->file('file_correction_pdf')->store('corrections', 'public');
        }

        $examen = Examen::create($data);
        return response()->json($examen, 201);
    }

    public function update(Request $request, $id)
    {
        $examen = Examen::findOrFail($id);

        $request->validate([
            'titre' => 'required|string|max:255',
            'file_exam_pdf' => 'nullable|file|mimes:pdf|max:2048',
            'file_correction_pdf' => 'nullable|file|mimes:pdf|max:2048'
        ]);

        $data = $request->except('file_exam_pdf', 'file_correction_pdf');

        if ($request->hasFile('file_exam_pdf')) {
            if ($examen->file_exam_pdf) {
                Storage::disk('public')->delete($examen->file_exam_pdf);
            }
            $data['file_exam_pdf'] = $request->file('file_exam_pdf')->store('examens', 'public');
        }

        if ($request->hasFile('file_correction_pdf')) {
            if ($examen->file_correction_pdf) {
                Storage::disk('public')->delete($examen->file_correction_pdf);
            }
            $data['file_correction_pdf'] = $request->file('file_correction_pdf')->store('corrections', 'public');
        }

        $examen->update($data);
        return response()->json($examen);
    }

    public function destroy($id)
    {
        $examen = Examen::findOrFail($id);

        if ($examen->file_exam_pdf) {
            Storage::disk('public')->delete($examen->file_exam_pdf);
        }

        if ($examen->file_correction_pdf) {
            Storage::disk('public')->delete($examen->file_correction_pdf);
        }

        $examen->delete();
        return response()->json(['message' => 'Examen supprimé avec succès']);
    }
}