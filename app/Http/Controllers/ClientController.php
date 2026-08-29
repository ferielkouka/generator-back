<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
  public function index()
  {
    return response()->json(Client::all());
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'name' => 'required|string|max:255',
      'email' => 'required|email|unique:clients,email',
      'phone' => 'required|string|max:20'
    ]);

    $client = Client::create($validated);
    return response()->json($client, 201);
  }

  public function update(Request $request, $id)
  {
    $client = Client::findOrFail($id);

    $validated = $request->validate([
      'name' => 'required|string|max:255',
      'email' => 'required|email|unique:clients,email,' . $id,
      'phone' => 'required|string|max:20'
    ]);

    $client->update($validated);
    return response()->json($client);
  }

  public function destroy($id)
  {
    Client::destroy($id);
    return response()->json(['message' => 'Client supprimé avec succès']);
  }
}