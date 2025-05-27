<?php
namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'url',
        'status',
        'last_crawled_at',
        'crawl_version_id',
    ];

    protected $casts = [
        'status' => SiteStatus::class,
        'last_crawled_at' => 'datetime',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class); // Via dénormalisation de site_id sur chunks
    }

    public function crawlVersion(): BelongsTo
    {
        return $this->belongsTo(CrawlVersion::class);
    }
}