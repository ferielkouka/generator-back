<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Contact;
class ContactsController extends Controller{
public function store(Request $request){
$item=Contact::create($request->all());
return response()->json($item,201);
}
public function index(){
return response()->json(Contact::all());
}}