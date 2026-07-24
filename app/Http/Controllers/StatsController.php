<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Article;
use App\Models\Contact;

class StatsController extends Controller
{
  public function index()
  {
    $stats = [
      'students' => Student::count(),
      'articles' => Article::count(),
      'contacts' => Contact::count()
    ];
    return response()->json($stats);
  }
}