<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_types', function (Blueprint $table) {
            // On ajoute la colonne pour stocker les champs de formulaire.
            // Le type 'json' est idéal pour ça.
            $table->json('required_fields')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('task_types', function (Blueprint $table) {
            $table->dropColumn('required_fields');
        });
    }
};