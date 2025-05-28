<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\SiteStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) { $table->id(); $table->string('url')->unique(); // URL unique globalement 
        $table->string('status_api')->default(SiteStatus::PENDING_SUBMISSION->value); 
        $table->string('fastapi_job_id')->nullable()->comment('ID de la tâche retourné par FastAPI'); 
        $table->timestamp('last_sent_to_api_at')->nullable(); 
        $table->text('last_api_response')->nullable()->comment('Dernière réponse (succès/erreur) de FastAPI'); 
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
