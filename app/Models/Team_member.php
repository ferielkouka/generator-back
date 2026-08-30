<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team_member extends Model
{
    protected $table = 'team_members';
    protected $fillable = ['name', 'role', 'email'];
}
