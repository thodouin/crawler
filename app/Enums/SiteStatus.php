<?php 

namespace App\Enums; 

enum SiteStatus: string { 
    case PENDING_SUBMISSION = 'pending_submission_to_api'; 
    case SUBMITTED_TO_API = 'submitted_to_api'; 
    case PROCESSING_BY_API = 'processing_by_api'; 
    case COMPLETED_BY_API = 'completed_by_api'; 
    case FAILED_API_SUBMISSION = 'api_submission_failed'; 
    case FAILED_PROCESSING_BY_API = 'api_processing_failed'; 
}