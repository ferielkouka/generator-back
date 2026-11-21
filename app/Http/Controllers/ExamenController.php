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
    $data = $request->all();
    if ($request->hasFile('exam_pdf')) {
      $data['exam_pdf'] = $request->file('exam_pdf')->store('uploads','public');
    }
    if ($request->hasFile('correction_pdf')) {
      $data['correction_pdf'] = $request->file('correction_pdf')->store('uploads','public');
    }
    $examen = Examen::create($data);
    return response()->json($examen, 201);
  }

  public function update(Request $request, $id)
  {
    $examen = Examen::findOrFail($id);
    $data = $request->all();
    if ($request->hasFile('exam_pdf')) {
      $data['exam_pdf'] = $request->file('exam_pdf')->store('uploads','public');
    }
    if ($request->hasFile('correction_pdf')) {
      $data['correction_pdf'] = $request->file('correction_pdf')->store('uploads','public');
    }
    $examen->update($data);
    return response()->json($examen);
  }

  public function destroy($id)
  {
    Examen::destroy($id);
    return response()->json(['message' => 'Deleted']);
  }
}