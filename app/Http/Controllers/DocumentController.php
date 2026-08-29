<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index()
    {
        return response()->json(Document::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf|max:2048'
        ]);

        $filePath = $request->file('file')->store('documents', 'public');

        $document = Document::create([
            'title' => $request->title,
            'file_path' => $filePath
        ]);

        return response()->json($document, 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'file' => 'nullable|file|mimes:pdf|max:2048'
        ]);

        $document = Document::findOrFail($id);

        if ($request->hasFile('file')) {
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
            $document->file_path = $request->file('file')->store('documents', 'public');
        }

        if ($request->filled('title')) {
            $document->title = $request->title;
        }

        $document->save();

        return response()->json($document);
    }

    public function destroy($id)
    {
        $document = Document::findOrFail($id);

        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document supprimé avec succès']);
    }
}