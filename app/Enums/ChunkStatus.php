<?php
namespace App\Enums;

enum ChunkStatus: string {
    case PENDING_EMBEDDING = 'pending_embedding';
    case EMBEDDING = 'embedding'; // Optionnel si c'est un processus long
    case EMBEDDED = 'embedded';
    case FAILED = 'failed';
}