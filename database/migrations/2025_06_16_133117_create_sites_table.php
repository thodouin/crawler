<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\SiteStatus;
use App\Enums\SitePriority;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) { 
        $table->id();
        // crawler_worker_id lie au worker qui est/sera assigné
        $table->foreignId('crawler_worker_id')->nullable()->constrained('crawler_workers')->onDelete('set null');
        $table->string('url')->unique();
        $table->string('status_api')->nullable()->default(null);
        $table->string('priority')->default(SitePriority::NORMAL->value)->nullable();
        $table->string('fastapi_job_id')->nullable()->comment('ID de tâche retourné par FastAPI (si applicable)');
        $table->timestamp('last_sent_to_api_at')->nullable();
        $table->text('last_api_response')->nullable();
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
