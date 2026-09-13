<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fournisseur extends Model
{
    protected $table = 'fournisseurs';
    protected $fillable = ['fournisseur', 'produit', 'quantity', 'price', 'total'];
}
