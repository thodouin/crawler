<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SitePriority: string implements HasLabel {
    case URGENT = 'urgent';
    case NORMAL = 'normal';
    case LOW = 'low';

    public function getLabel(): ?string {
        return match ($this) {
            self::URGENT => 'En urgence',
            self::NORMAL => 'Normal',
            self::LOW    => 'Lent',
        };
    }
    // Optionnel : public function getColor(): string|array|null { ... }
}