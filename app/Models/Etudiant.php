<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Etudiant extends Model
{
    protected $table = 'etudiants';
    protected $fillable = ['nom', 'prenom', 'email', 'telephone', 'dateNaissance'];
}
