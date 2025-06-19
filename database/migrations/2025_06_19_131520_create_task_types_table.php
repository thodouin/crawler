<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Ex: "Crawler le site complet"
            $table->string('slug')->unique(); // Ex: "crawl", utilisé par le code
            $table->text('description')->nullable(); // Explique ce que fait la tâche
            $table->boolean('is_active')->default(true); // Pour activer/désactiver une tâche
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_types');
    }
};