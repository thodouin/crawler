<?php

namespace App\Models;

use App\Enums\CrawlVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_name',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'status' => CrawlVersionStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}