<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Offre extends Model
{
    protected $table = 'offres';
    protected $fillable = ['titre', 'description', 'salaire'];
}
