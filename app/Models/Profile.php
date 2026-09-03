<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $table = 'profiles';
    protected $fillable = ['nom', 'prenom', 'telephone', 'photo_date', 'video_date', 'photo', 'video'];
}
