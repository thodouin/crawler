<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'required_fields',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'required_fields' => 'array',
    ];
}