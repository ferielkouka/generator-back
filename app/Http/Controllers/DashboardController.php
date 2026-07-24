<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Article;
use App\Models\Contact;

class DashboardController extends Controller
{
  public function countStudents()
  {
    $count = Student::count();
    return response()->json(['count' => $count], 200);
  }

  public function countArticles()
  {
    $count = Article::count();
    return response()->json(['count' => $count], 200);
  }

  public function countContacts()
  {
    $count = Contact::count();
    return response()->json(['count' => $count], 200);
  }
}