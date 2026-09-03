<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
  public function login(Request $request)
  {
    $credentials = $request->validate([
      'email' => 'required|email',
      'password' => 'required',
    ]);

    if (Auth::attempt($credentials)) {
      $user = User::where('email', $request->email)->first();
      $token = $user->createToken('authToken')->plainTextToken;
      return response()->json(['token' => $token]);
    }

    return response()->json(['error' => 'Unauthorized'], 401);
  }

  public function register(Request $request)
  {
    $validated = $request->validate([
      'email' => 'required|email|unique:users',
      'password' => 'required|min:6',
    ]);

    $user = User::create([
      'email' => $validated['email'],
      'password' => Hash::make($validated['password']),
    ]);

    $token = $user->createToken('authToken')->plainTextToken;
    return response()->json(['token' => $token], 201);
  }

  public function logout(Request $request)
  {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Logged out']);
  }

  public function user(Request $request)
  {
    return response()->json($request->user());
  }
}