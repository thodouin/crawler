<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_id',
        'site_id', // Dénormalisé
        'content',
        'status',
        'embedded_at',
        'embedding_model_version',
        'crawl_version_id',
    ];

    protected $casts = [
        'status' => ChunkStatus::class,
        'embedded_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class); // Relation grâce à la dénormalisation
    }

    public function crawlVersion(): BelongsTo
    {
        return $this->belongsTo(CrawlVersion::class);
    }
}