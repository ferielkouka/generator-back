<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Blog;
class BlogController extends Controller{
public function store(Request $request){
$item=Blog::create($request->all());
return response()->json($item,201);
}
public function index(){
return response()->json(Blog::all());
}}