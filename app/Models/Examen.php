<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Examen extends Model
{
    protected $table = 'examens';
    protected $fillable = ['titre', 'examen_pdf', 'correction_pdf'];
}
