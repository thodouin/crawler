<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->text('url'); // text pour des URLs potentiellement longues
            $table->string('status')->default(App\Enums\PageStatus::PENDING_CRAWL->value);
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('sitemap_last_updated_at')->nullable();
            $table->string('content_hash')->nullable();
            $table->foreignId('crawl_version_id')->nullable()->constrained('crawl_versions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'url']); // S'assurer que l'URL est unique par site
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
