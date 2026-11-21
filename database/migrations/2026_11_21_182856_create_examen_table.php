<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('examen', function (Blueprint $table) {
      $table->id();
      $table->string('titre');
      $table->string('exam_pdf')->nullable();
      $table->string('correction_pdf')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('examen');
  }
};