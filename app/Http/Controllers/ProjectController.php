<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Conversation;

class ProjectController extends Controller
{
    // Récupérer tous les projets
    public function index()
    {
        $projects = Project::orderBy('created_at', 'desc')->get();
        return response()->json($projects);
    }

    // Récupérer l'historique d'un projet
    public function history($id)
    {
        $conversations = Conversation::where('project_id', $id)
            ->orderBy('created_at')
            ->get();
        return response()->json($conversations);
    }

    // Créer un nouveau projet
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $project = Project::create([
            'name' => $request->input('name'),
            'path' => base_path('../generator-front'),
        ]);
        return response()->json($project);
    }
}