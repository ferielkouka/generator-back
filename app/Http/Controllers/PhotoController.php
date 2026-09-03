<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
  public function index()
  {
    return response()->json(Photo::all());
  }

  public function store(Request $request)
  {
    $request->validate([
      'title' => 'required|string|max:255',
      'description' => 'nullable|string',
      'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
    ]);

    $imagePath = $request->file('image')->store('photos', 'public');

    $photo = Photo::create([
      'title' => $request->title,
      'description' => $request->description,
      'image' => $imagePath
    ]);

    return response()->json($photo, 201);
  }

  public function update(Request $request, $id)
  {
    $request->validate([
      'title' => 'required|string|max:255',
      'description' => 'nullable|string',
      'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
    ]);

    $photo = Photo::findOrFail($id);

    if ($request->hasFile('image')) {
      if ($photo->image) {
        Storage::disk('public')->delete($photo->image);
      }
      $imagePath = $request->file('image')->store('photos', 'public');
      $photo->image = $imagePath;
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

    if ($photo->image) {
      Storage::disk('public')->delete($photo->image);
    }

    $photo->delete();

    return response()->json(['message' => 'Photo supprimée avec succès']);
  }
}