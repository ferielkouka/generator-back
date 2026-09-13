<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fournisseurs', function (Blueprint $table) {
      $table->id();
      $table->string('fournisseur');
      $table->string('produit');
      $table->integer('quantity');
      $table->decimal('price', 10, 2);
      $table->decimal('total', 10, 2);
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('fournisseurs');
  }
};
