<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('offre_recrutements', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->text('description');
      $table->integer('salary');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('offre_recrutements');
  }
};