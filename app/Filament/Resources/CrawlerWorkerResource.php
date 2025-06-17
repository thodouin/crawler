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
            Forms\Components\TextInput::make('worker_identifier')->label('ID Worker (FastAPI)')->disabled(),
            Forms\Components\TextInput::make('name')->label('Nom du Worker')->required(),
            Forms\Components\Select::make('ws_protocol')->label('Protocole WS')->options(['ws'=>'ws', 'wss'=>'wss'])->disabled(),
            Forms\Components\Select::make('status')->label('Statut Actuel')->options(WorkerStatus::class)->disabled(),
            Forms\Components\TextInput::make('current_site_id_processing')->label('Site ID en cours')->disabled(),
            Forms\Components\DateTimePicker::make('last_heartbeat_at')->label('Dernier Heartbeat')->disabled(),
        ]);
    }
    public static function table(Table $table): Table {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('worker_identifier')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('status')->badge()->searchable()->sortable()
                 ->color(fn (WorkerStatus $state): string => $state->getColor()), // Utilise la méthode de l'Enum
            Tables\Columns\TextColumn::make('processingSite.url')->label('Site en Cours')->limit(30),
            Tables\Columns\TextColumn::make('last_heartbeat_at')->dateTime('d/m/Y H:i:s')->sortable(),
        ])->actions([Tables\Actions\ViewAction::make(), /* Tables\Actions\EditAction::make(), */ Tables\Actions\DeleteAction::make()]) // L'édition par l'admin est limitée
          ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }
    public static function getPages(): array {
        return [
            'index' => Pages\ListCrawlerWorkers::route('/'),
            // 'create' => Pages\CreateCrawlerWorker::route('/create'), // Pas de création manuelle
            'edit' => Pages\EditCrawlerWorker::route('/{record}/edit'),
        ];
    }
}