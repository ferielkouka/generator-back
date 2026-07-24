<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Etudiant;
use App\Models\Article;
use App\Models\Contact;

class StatistiquesController extends Controller
{
  public function index()
  {
    $etudiants = Etudiant::count();
    $articles = Article::count();
    $contacts = Contact::count();
    return response()->json(['etudiants' => $etudiants, 'articles' => $articles, 'contacts' => $contacts]);
  }
}