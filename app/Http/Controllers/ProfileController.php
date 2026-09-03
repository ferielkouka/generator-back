<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Profile;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
  public function index()
  {
    return response()->json(Profile::all());
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'nom' => 'required|string|max:255',
      'prenom' => 'required|string|max:255',
      'telephone' => 'required|string|max:20',
      'photo_date' => 'nullable|date',
      'video_date' => 'nullable|date',
      'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
      'video' => 'nullable|mimes:mp4,mov,avi,wmv|max:10240'
    ]);

    if ($request->hasFile('photo')) {
      $data['photo'] = $request->file('photo')->store('profiles/photos', 'public');
    }

    if ($request->hasFile('video')) {
      $data['video'] = $request->file('video')->store('profiles/videos', 'public');
    }

    $profile = Profile::create($data);
    return response()->json($profile, 201);
  }

  public function update(Request $request, $id)
  {
    $profile = Profile::findOrFail($id);

    $data = $request->validate([
      'nom' => 'required|string|max:255',
      'prenom' => 'required|string|max:255',
      'telephone' => 'required|string|max:20',
      'photo_date' => 'nullable|date',
      'video_date' => 'nullable|date',
      'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
      'video' => 'nullable|mimes:mp4,mov,avi,wmv|max:10240'
    ]);

    if ($request->hasFile('photo')) {
      if ($profile->photo) {
        Storage::disk('public')->delete($profile->photo);
      }
      $data['photo'] = $request->file('photo')->store('profiles/photos', 'public');
    }

    if ($request->hasFile('video')) {
      if ($profile->video) {
        Storage::disk('public')->delete($profile->video);
      }
      $data['video'] = $request->file('video')->store('profiles/videos', 'public');
    }

    $profile->update($data);
    return response()->json($profile);
  }

  public function destroy($id)
  {
    $profile = Profile::findOrFail($id);

    if ($profile->photo) {
      Storage::disk('public')->delete($profile->photo);
    }

    if ($profile->video) {
      Storage::disk('public')->delete($profile->video);
    }

    $profile->delete();
    return response()->json(['message' => 'Profil supprimé avec succès']);
  }

  public function storeCalendar(Request $request)
  {
    $data = $request->validate([
      'date' => 'required|date',
      'event' => 'required|string'
    ]);

    return response()->json(['message' => 'Événement ajouté au calendrier', 'data' => $data], 201);
  }
}