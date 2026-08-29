<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Offre_recrutement extends Model
{
    protected $table = 'offre_recrutements';
    protected $fillable = ['title', 'description', 'salary'];
}
