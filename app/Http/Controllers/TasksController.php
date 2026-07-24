<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Task;
class TasksController extends Controller{
  public function store(Request $request){
    $task=Task::create($request->all());
    return response()->json($task,201);
  }
  public function index(){
    return response()->json(Task::all());
  }
}