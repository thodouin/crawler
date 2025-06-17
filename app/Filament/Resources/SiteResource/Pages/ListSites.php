<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Enums\SitePriority; // Importer l'Enum de priorité
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder; // Importer Builder
use Livewire\Attributes\On;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    public ?string $maxContentWidth = 'full';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    // DÉFINIR LES ONGLETS ICI
    public function getTabs(): array
    {
        $tabs = [];

        // Onglet "Tous" (généralement ajouté par défaut par Filament si aucun autre onglet n'est actif)
        // Si vous voulez le contrôler explicitement :
        $tabs['all'] = ListRecords\Tab::make('Tous')
            ->badge(static::getResource()::getEloquentQuery()->count()); // Compte tous les sites (respectant le scope de base)

        // Créer un onglet pour chaque cas de SitePriority
        foreach (SitePriority::cases() as $priority) {
            $tabs[$priority->value] = ListRecords\Tab::make($priority->getLabel()) // Utilise le label de l'Enum
                ->modifyQueryUsing(fn (Builder $query) => $query->where('priority', $priority->value))
                ->badge(static::getResource()::getEloquentQuery()->where('priority', $priority->value)->count()); // Compte pour cet onglet
        }
        return $tabs;
    }

    #[On('echo:sites-table-updates,SiteRowShouldRefresh')] 
    public function onSiteChange(array $eventData): void // Le nom de la méthode peut être ce que vous voulez
    {
        \Illuminate\Support\Facades\Log::info('ListSites Livewire: Received SiteRowShouldRefresh via Echo. Refreshing table.', $eventData);
        $this->resetTable();
}
}