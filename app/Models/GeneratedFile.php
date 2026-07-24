<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedFile extends Model
{
    protected $fillable = ['project_id', 'filepath', 'content'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}