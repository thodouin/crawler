<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel; // IMPORTER L'INTERFACE

enum SiteStatus: string implements HasLabel // IMPLÉMENTER L'INTERFACE
{
    case PENDING_ASSIGNMENT = 'pending_server_assignment';
    case PENDING_SUBMISSION = 'pending_submission_to_api';
    case SUBMITTED_TO_API = 'submitted_to_api';
    case PROCESSING_BY_API = 'processing_by_api';
    case COMPLETED_BY_API = 'completed_by_api';
    case FAILED_API_SUBMISSION = 'api_submission_failed';
    case FAILED_PROCESSING_BY_API = 'api_processing_failed';
    // Ajoutez d'autres cas si nécessaire

    // MÉTHODE REQUISE PAR HasLabel
    public function getLabel(): ?string
    {
        return match ($this) {
            self::PENDING_ASSIGNMENT => 'En attente de serveur',
            self::PENDING_SUBMISSION => 'En attente d\'envoi API',
            self::SUBMITTED_TO_API => 'Soumis à l\'API',
            self::PROCESSING_BY_API => 'En cours (API)',
            self::COMPLETED_BY_API => 'Terminé (API)',
            self::FAILED_API_SUBMISSION => 'Échec envoi API',
            self::FAILED_PROCESSING_BY_API => 'Échec traitement API',
        };
    }
}