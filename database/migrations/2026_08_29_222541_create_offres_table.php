<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('offres', function (Blueprint $table) {
      $table->id();
      $table->string('titre');
      $table->text('description');
      $table->integer('salaire');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('offres');
  }
};