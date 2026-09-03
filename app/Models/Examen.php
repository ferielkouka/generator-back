<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Examen extends Model
{
    protected $table = 'examens';
    protected $fillable = ['titre', 'file_exam_pdf', 'file_correction_pdf'];
}
