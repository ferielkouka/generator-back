<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
{
    public function index()
    {
        return response()->json(Client::all());
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'required|string|regex:/^[0-9]{10,15}$/'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $client = Client::create($request->all());
        return response()->json($client, 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'phone' => 'required|string|regex:/^[0-9]{10,15}$/'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $client = Client::findOrFail($id);
        $client->update($request->all());
        return response()->json($client);
    }

    public function destroy($id)
    {
        Client::destroy($id);
        return response()->json(['message' => 'Client supprimé avec succès']);
    }
}