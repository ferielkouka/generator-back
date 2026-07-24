<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Commentaire;
class CommentairesController extends Controller{
public function store(Request $request){
$item=Commentaire::create($request->all());
return response()->json($item,201);
}
public function index(){
return response()->json(Commentaire::all());
}}