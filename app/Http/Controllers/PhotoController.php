<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        if ($search) {
            return response()->json(Photo::where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->get());
        }
        return response()->json(Photo::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $file = $request->file('file');
        $path = $file->store('photos', 'public');

        $photo = Photo::create([
            'title' => $request->title,
            'description' => $request->description,
            'file_path' => Storage::url($path)
        ]);

        return response()->json($photo, 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $photo = Photo::findOrFail($id);

        if ($request->hasFile('file')) {
            if ($photo->file_path) {
                Storage::disk('public')->delete(str_replace(Storage::url(''), '', $photo->file_path));
            }
            $path = $request->file('file')->store('photos', 'public');
            $photo->file_path = Storage::url($path);
        }

        $photo->update([
            'title' => $request->title,
            'description' => $request->description
        ]);

        return response()->json($photo);
    }

    public function destroy($id)
    {
        $photo = Photo::findOrFail($id);

        if ($photo->file_path) {
            Storage::disk('public')->delete(str_replace(Storage::url(''), '', $photo->file_path));
        }

        $photo->delete();

        return response()->json(['message' => 'Photo supprimée avec succès']);
    }
}