<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Examen extends Model
{
    protected $table = 'examen';
    protected $fillable = ['titre', 'exam_pdf', 'correction_pdf'];
}
