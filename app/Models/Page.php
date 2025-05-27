<?php

namespace App\Models;

use App\Enums\PageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'url',
        'status',
        'last_crawled_at',
        'sitemap_last_updated_at',
        'content_hash',
        'crawl_version_id',
    ];

    protected $casts = [
        'status' => PageStatus::class,
        'last_crawled_at' => 'datetime',
        'sitemap_last_updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function crawlVersion(): BelongsTo
    {
        return $this->belongsTo(CrawlVersion::class);
    }
}