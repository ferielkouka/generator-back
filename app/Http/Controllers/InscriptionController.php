<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class InscriptionController extends Controller
{
    public function inscription(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $nom = $request->input('nom');
        $prenom = $request->input('prenom');

        $user = new User();
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->nom = $nom;
        $user->prenom = $prenom;
        $user->save();

        return response()->json(['message' => 'Inscription réussie']);
    }
}