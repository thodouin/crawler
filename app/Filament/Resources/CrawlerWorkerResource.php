<?php
namespace App\Filament\Resources;

use App\Filament\Resources\CrawlerWorkerResource\Pages;
use App\Models\CrawlerWorker;
use App\Enums\WorkerStatus; // Importer l'Enum
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Infolist; // <--- AJOUTER CET IMPORT
use Filament\Infolists;         // <--- AJOUTER CET IMPORT (pour Infolists\Components)
use Carbon\Carbon;              // <--- AJOUTER CET IMPORT (utilisé dans la table)
use Illuminate\Support\Facades\Auth;

class CrawlerWorkerResource extends Resource {
    protected static ?string $model = CrawlerWorker::class;
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Administration'; // Ou 'Crawling'
    protected static ?string $navigationLabel = 'Workers Crawler';
    protected static ?string $pluralModelLabel = 'workers crawler';
    protected static ?string $modelLabel = 'worker crawler';
    protected static ?int $navigationSort = 2;

    // L'admin ne crée pas les workers, ils s'enregistrent. On peut cacher le bouton de création.
    public static function canCreate(): bool { return false; }

    public static function form(Form $form): Form { // Pour l'édition par l'admin si besoin
        return $form->schema([
            Forms\Components\Section::make('Informations du Worker')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('worker_identifier')
                            ->label('ID Worker')
                            ->disabled() // Non modifiable par l'admin
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('name')
                            ->label('Nom du Worker')
                            ->required() // L'admin peut changer le nom convivial
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->label('Statut Actuel')
                            ->options(WorkerStatus::class) // L'Enum doit implémenter HasLabel
                            ->disabled(), // Le statut est mis à jour par le worker lui-même
                        Forms\Components\Select::make('ws_protocol')
                            ->label('Protocole WS')
                            ->options([
                                'ws' => 'ws',
                                'wss' => 'wss',
                            ])
                            ->disabled(),
                        Forms\Components\TextInput::make('current_site_id_processing')
                            ->label('Site ID en Cours de Traitement')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('last_heartbeat_at')
                            ->label('Dernier Heartbeat Reçu')
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Informations Système (rapportées par le worker)')
                    ->statePath('system_info') // Important pour lier les champs à la clé 'system_info'
                    ->columns(2)
                    ->collapsible()
                    ->disabled() // <--- DÉSACTIVER TOUTE LA SECTION
                    ->dehydrated(false)
                    ->schema([
                        Forms\Components\TextInput::make('platform')->label('Plateforme (OS)'),
                        Forms\Components\TextInput::make('os_type')->label('Type OS'),
                        Forms\Components\TextInput::make('os_release')->label('Version OS'),
                        Forms\Components\TextInput::make('cpu_model')->label('Modèle CPU')->columnSpanFull(),
                        Forms\Components\TextInput::make('cpu_cores')->label('Nb Cœurs CPU')->numeric(),
                        Forms\Components\TextInput::make('total_memory_gb')->label('RAM Totale (GB)')->numeric(),
                        Forms\Components\TextInput::make('free_memory_gb')->label('RAM Libre (GB)')->numeric(),
                    ])
        ]);
    }
    public static function table(Table $table): Table {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('worker_identifier')->label('ID Worker')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (WorkerStatus $state): string => $state->getLabel()) // Assurez-vous que WorkerStatus a getLabel()
                    ->color(fn (WorkerStatus $state): string => $state->getColor()) // Assurez-vous que WorkerStatus a getColor()
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('processingSite.url') // Utilise la relation pour afficher l'URL
                    ->label('Site en Cours')
                    ->placeholder('Aucun')
                    ->limit(30)
                    ->tooltip(fn(CrawlerWorker $record) => $record->processingSite?->url ?? ''),
                Tables\Columns\TextColumn::make('last_heartbeat_at')->label('Dernier Heartbeat')
                    ->formatStateUsing(fn (?string $state): string => $state ? Carbon::parse($state)->locale(config('app.locale','fr'))->diffForHumans() : 'Jamais')
                    ->sortable(),
                Tables\Columns\TextColumn::make('system_info.os_type')->label('OS (Type)')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('system_info.cpu_cores')->label('Cœurs CPU')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([Tables\Actions\ViewAction::make(), /* Tables\Actions\EditAction::make(), */ Tables\Actions\DeleteAction::make()]) // L'édition par l'admin est limitée
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array {
        return [
            'index' => Pages\ListCrawlerWorkers::route('/'),
            'create' => Pages\CreateCrawlerWorker::route('/create'), // Pas de création manuelle
            'edit' => Pages\EditCrawlerWorker::route('/{record}/edit'),
        ];
    }
}