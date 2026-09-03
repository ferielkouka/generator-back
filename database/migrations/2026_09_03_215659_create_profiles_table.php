<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('profiles', function (Blueprint $table) {
      $table->id();
      $table->string('nom');
      $table->string('prenom');
      $table->string('telephone');
      $table->date('photo_date')->nullable();
      $table->date('video_date')->nullable();
      $table->string('photo')->nullable();
      $table->string('video')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('profiles');
  }
};