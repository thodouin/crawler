<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On vérifie d'abord si la colonne n'existe pas déjà pour éviter les erreurs
        if (!Schema::hasColumn('sites', 'max_depth')) {
            Schema::table('sites', function (Blueprint $table) {
                // On ajoute la colonne pour stocker la profondeur.
                // Elle est nullable car non pertinente pour les autres types de tâches.
                $table->integer('max_depth')->nullable()->after('task_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('max_depth');
        });
    }
};