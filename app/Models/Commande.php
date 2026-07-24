<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commande extends Model
{
    protected $table = 'commande';
    protected $fillable = ['client', 'produit', 'quantite', 'prixTotal', 'statutLivraison'];
}
