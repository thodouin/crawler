<?php
namespace App\Enums;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum WorkerStatus: string implements HasLabel, HasColor {
    case ONLINE_IDLE = 'online_idle';
    case ONLINE_BUSY = 'online_busy';
    case OFFLINE = 'offline';
    // case ERROR = 'error'; // Optionnel

    public function getLabel(): ?string {
        return match ($this) {
            self::ONLINE_IDLE => 'En ligne (Libre)',
            self::ONLINE_BUSY => 'En ligne (Occupé)',
            self::OFFLINE => 'Hors ligne',
            // self::ERROR => 'Erreur',
        };
    }
    public function getColor(): string | array | null {
         return match ($this) {
            self::ONLINE_IDLE => 'success',
            self::ONLINE_BUSY => 'warning',
            self::OFFLINE => 'danger',
            // self::ERROR => 'danger',
        };
    }
}