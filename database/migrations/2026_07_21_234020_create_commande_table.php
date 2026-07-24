<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('commande', function (Blueprint $table) {
      $table->id();
      $table->string('client');
      $table->string('produit');
      $table->integer('quantite');
      $table->float('prixTotal');
      $table->string('statutLivraison');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('commande');
  }
};