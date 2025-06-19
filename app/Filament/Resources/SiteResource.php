<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus; // UTILISER LE BON ENUM PARTOUT
use App\Enums\SitePriority;
use App\Enums\WorkerStatus;
use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use App\Models\User;
use App\Models\CrawlerWorker;
use App\Events\SiteStatusUpdated;
use App\Events\CrawlerWorkerStatusChanged;
use App\Services\FastApiService; // Pour l'appel direct
use App\Jobs\SendSiteToFastApiJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder; // IMPORTANT pour le scope
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;
    protected static ?string $navigationGroup = 'Crawling';
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'Sites à Crawler';
    protected static ?string $pluralModelLabel = 'sites';
    protected static ?string $modelLabel = 'site';

    public static function getEloquentQuery(): Builder
 {
     // Si l'utilisateur connecté est l'admin spécifique, il voit tous les sites.
     if (Auth::check() && Auth::user()->email === 'admin@admin.com') {
         return parent::getEloquentQuery();
     }
     return parent::getEloquentQuery()->where('user_id', Auth::id());
 }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('url')
                ->label('URL du Site à crawler')
                ->url()
                ->required()
                ->unique(table: Site::class, column: 'url', ignoreRecord: true)
                ->maxLength(2048)
                ->columnSpanFull(),

            Forms\Components\Select::make('status_api') // Nom de la colonne dans la BDD
                ->label('Statut API')
                ->options(SiteStatus::class) // L'Enum doit implémenter HasLabel
                ->disabled()
                ->dehydrated(false) // Ne pas sauvegarder via le formulaire si désactivé
                ->columnSpanFull(),

            Forms\Components\Select::make('priority') // CHAMP PRIORITÉ À LA CRÉATION/ÉDITION
                ->label('Priorité du Crawl')
                ->options(SitePriority::class) // Utilise votre Enum SitePriority
                ->default(SitePriority::NORMAL->value) // Défaut à Normal
                ->required()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('fastapi_job_id')
                ->label('ID Tâche FastAPI')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\DateTimePicker::make('last_sent_to_api_at')
                ->label('Dernier envoi/réponse API') // Libellé plus précis
                ->disabled()
                ->dehydrated(false),

            Forms\Components\Textarea::make('last_api_response')
                ->label('Dernière réponse API')
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('url')
                ->label('URL')
                ->searchable()
                ->sortable()
                ->limit(70)
                ->tooltip(fn(Site $record) => $record->url),

                Tables\Columns\TextColumn::make('status_api') // Nom de la colonne
                    ->label('Statut API')
                    ->badge()
                    ->color(fn (SiteStatus $state): string => match ($state) {
                        SiteStatus::PENDING_ASSIGNMENT => 'heroicon-o-clock',
                        SiteStatus::PENDING_SUBMISSION => 'gray',
                        SiteStatus::SUBMITTED_TO_API => 'info',
                        SiteStatus::PROCESSING_BY_API => 'warning',
                        SiteStatus::COMPLETED_BY_API => 'success',
                        SiteStatus::FAILED_API_SUBMISSION => 'danger',
                        SiteStatus::FAILED_PROCESSING_BY_API => 'danger',
                        null => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?SiteStatus $state): string => $state?->getLabel() ?? 'N/A')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('existence_status')
                    ->label('Statut Existence')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'exists' => 'success',
                        'not_found' => 'danger',
                        'error' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'exists' => 'Existe',
                        'not_found' => 'Non trouvé',
                        'error' => 'Erreur',
                        default => 'N/A',
                    })
                    ->sortable()
                    ->toggleable(),

                    Tables\Columns\TextColumn::make('priority')
                    ->label('Priorité')
                    ->badge()
                    // Le $state ici sera une instance de SitePriority ou null
                    ->color(fn (?SitePriority $state): string => match ($state) { // <<< TYPE HINT CORRIGÉ
                        SitePriority::URGENT => 'danger',  // Comparer directement avec les cas de l'Enum
                        SitePriority::NORMAL => 'primary',
                        SitePriority::LOW    => 'gray',
                        null => 'secondary', // Gérer le cas où la priorité est nulle
                        // default n'est pas nécessaire si tous les cas de l'enum + null sont couverts
                    })
                    // Le $state ici sera aussi une instance de SitePriority ou null
                    ->formatStateUsing(function (?SitePriority $state): string { // <<< TYPE HINT CORRIGÉ
                        if (is_null($state)) {
                            return 'N/A';
                        }
                        // L'Enum SitePriority implémente HasLabel, donc getLabel() est disponible
                        return $state->getLabel(); 
                    })
                    ->sortable() // Le tri se fera sur la valeur brute en BDD ('urgent', 'normal', 'low')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('crawlerWorker.name') // AFFICHER LE NOM DU WORKER
                    ->label('Worker Assigné')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Non assigné')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_sent_to_api_at')
                    ->label('Dernière Activité API')
                    ->formatStateUsing(function (?string $state): string { // Utiliser formatStateUsing pour personnaliser l'affichage
                        if (is_null($state)) {
                            return 'Jamais'; // Ou ce que vous préférez si la date est nulle
                        }
                        // Carbon::parse($state) convertit la chaîne de date de la BDD en objet Carbon
                        // ->diffForHumans() génère la chaîne relative
                        return Carbon::parse($state)->locale(config('app.locale', 'fr'))->diffForHumans();
                    })
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('fastapi_job_id')
                    ->label('ID FastAPI')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status_api') // Nom de la colonne
                    ->options(SiteStatus::class) // L'Enum doit implémenter HasLabel
                    ->label('Filtrer par Statut API'), // Label pour le filtre

                Tables\Filters\SelectFilter::make('user_id') // Filtre par utilisateur assigné
                    ->label('Filtrer par Serveur')
                    ->options(fn () => User::where('email', '!=', 'admin@admin.com')->pluck('name', 'id')) // Lister les serveurs
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('crawler_worker_id')
                    ->label('Filtrer par Worker')
                    ->relationship('crawlerWorker', 'name') // Filtrer par la relation
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn(Site $record): bool => $record->status_api === SiteStatus::PENDING_SUBMISSION || $record->status_api === SiteStatus::FAILED_API_SUBMISSION),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
            
                    // BULK ACTION POUR MARQUER URGENT
                    Tables\Actions\BulkAction::make('markPriorityUrgent')
                        ->label('Marquer Priorité: Urgent')
                        ->icon('heroicon-o-exclamation-triangle') // Icône plus adaptée pour marquer
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Changer la priorité en Urgent')
                        ->modalDescription('Voulez-vous marquer les sites sélectionnés comme Urgents ? Cela ne les renverra pas à FastAPI.')
                        ->action(fn (EloquentCollection $records) => 
                            $records->each->update(['priority' => SitePriority::URGENT->value])
                        )
                        ->deselectRecordsAfterCompletion(), // Bonne pratique
            
                    // BULK ACTION POUR MARQUER NORMAL
                    Tables\Actions\BulkAction::make('markPriorityNormal')
                        ->label('Marquer Priorité: Normal')
                        ->icon('heroicon-o-check-circle')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Changer la priorité en Normal')
                        ->modalDescription('Voulez-vous marquer les sites sélectionnés comme Normaux ?')
                        ->action(fn (EloquentCollection $records) => 
                            $records->each->update(['priority' => SitePriority::NORMAL->value])
                        )
                        ->deselectRecordsAfterCompletion(),
            
                    // BULK ACTION POUR MARQUER LENT
                    Tables\Actions\BulkAction::make('markPriorityLow')
                        ->label('Marquer Priorité: Lent')
                        ->icon('heroicon-o-minus-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Changer la priorité en Lent')
                        ->modalDescription('Voulez-vous marquer les sites sélectionnés comme Lents ?')
                        ->action(fn (EloquentCollection $records) => 
                            $records->each->update(['priority' => SitePriority::LOW->value])
                        )
                        ->deselectRecordsAfterCompletion(),
                    
                    // LA BULKACTION SÉPARÉE POUR ENVOYER (utilise la priorité existante du site)
                    Tables\Actions\BulkAction::make('assignAndSendSelectedToFastApiJobAction')
                        ->label('Assigner & Envoyer Sélection (Job)')
                        ->icon('heroicon-o-arrow-up-on-square-stack')
                        ->color('success')
                        ->requiresConfirmation()
                        ->form([
                                // NOUVEAU CHAMP POUR CHOISIR LA TÂCHE
                            Forms\Components\Select::make('task_type')
                                ->label('Type de Tâche')
                                ->options([
                                    'crawl' => 'Crawler le site complet',
                                    'check_existence' => 'Vérifier seulement si le site existe',
                                ])
                                ->default('crawl')
                                ->required(),

                            Forms\Components\TextInput::make('max_depth')
                                ->numeric()->default(0)->minValue(0)
                                ->required(fn (Forms\Get $get): bool => !$get('crawl_all_links') && $get('task_type') === 'crawl')
                                ->visible(fn (Forms\Get $get) => $get('task_type') === 'crawl'),

                            Forms\Components\Checkbox::make('crawl_all_links')
                                ->default(false)
                                ->reactive()
                                ->visible(fn (Forms\Get $get) => $get('task_type') === 'crawl'),
                        ])
                        ->modalHeading('Assigner et Envoyer les sites sélectionnés à Électron')
                        ->action(function (EloquentCollection $records, array $data) {
                            $taskType = $data['task_type'];
                
                            // La profondeur n'est pertinente que pour le crawl
                            $maxDepthForBulk = null;
                            if ($taskType === 'crawl') {
                                $maxDepthForBulk = $data['crawl_all_links'] ? -1 : (int) $data['max_depth'];
                            }
                            
                            $jobDispatchedCount = 0;
                            $skippedCount = 0;
                            $assignedCount = 0;
                            $putInQueueForServerCount = 0;

                            // Tri des enregistrements par priorité (votre logique de tri ici)
                            $priorityOrder = [
                                SitePriority::URGENT->value => 1,
                                SitePriority::NORMAL->value => 2,
                                SitePriority::LOW->value    => 3,
                                null => 4, 
                            ];
                        
                            $sortedRecords = $records->sortBy(function ($site) use ($priorityOrder) {
                                $priorityValue = $site->priority?->value;
                                return $priorityOrder[$priorityValue] ?? $priorityOrder[null];
                            })->values();

                            Log::info("BulkAction: Sites à traiter après tri par priorité:", ['count' => $sortedRecords->count()]);

                            foreach ($sortedRecords as $record) {
                                $isEligible = is_null($record->status_api) ||
                                              $record->status_api === SiteStatus::PENDING_ASSIGNMENT ||
                                              $record->status_api === SiteStatus::FAILED_API_SUBMISSION;
                    
                                if ($isEligible) {
                                    // Tenter de trouver un CrawlerWorker LIBRE
                                    $availableWorker = CrawlerWorker::where('status', WorkerStatus::ONLINE_IDLE)
                                                          ->whereNull('current_site_id_processing')
                                                          ->orderBy('last_heartbeat_at', 'asc') // Pour choisir le premier libre par ID
                                                          ->first();
                            
                                    if ($availableWorker) {
                                        Log::info("BulkAction: Site ID {$record->id} assigné au Worker ID {$availableWorker->id} et mis en attente de récupération.");
                                                            
                                        $record->crawler_worker_id = $availableWorker->id;
                                        $record->status_api = SiteStatus::PENDING_SUBMISSION;
                                        $record->task_type = $taskType; // <-- ON SAUVEGARDE LE TYPE DE TÂCHE !
                                        $record->last_api_response = 'Tâche (' . $taskType . ') en attente de récupération par: ' . $availableWorker->name;
                                        $record->save();
                                                                                
                                        if (class_exists(SiteStatusUpdated::class)) {
                                            SiteStatusUpdated::dispatch($record->fresh());
                                        }
                                                            
                                        $assignedCount++;
                                        // SendSiteToFastApiJob::dispatch($record, $maxDepthForBulk);
                                        // $jobDispatchedCount++;
                                    } else {
                                        $putInQueueForServerCount++;
                                        $record->crawler_worker_id = null; // S'assurer qu'il n'y a pas d'ancienne assignation
                                        $record->status_api = SiteStatus::PENDING_ASSIGNMENT;
                                        $record->last_api_response = 'En attente d\'un Worker FastAPI disponible.';
                                        $record->save();
                                        if (class_exists(SiteStatusUpdated::class)) {
                                            SiteStatusUpdated::dispatch($record->fresh());
                                        }
                                        Log::info("BulkAction: Aucun Worker LIBRE pour Site ID {$record->id}. Mis en statut PENDING_ASSIGNMENT.");
                                    }
                                } else {
                                    $skippedCount++;
                                    Log::info("BulkAction: Site ID {$record->id} ignoré (statut: " . ($record->status_api?->value ?? 'NULL') . ")");
                                }
                            }
                            
                            // ... (Logique de notification) ...
                            $messages = [];
                            if ($assignedCount > 0) $messages[] = "{$assignedCount} site(s) assignés à un worker et préparés.";
                            // if ($jobDispatchedCount > 0) $messages[] = "{$jobDispatchedCount} job(s) d'envoi mis en file d'attente.";
                            if ($putInQueueForServerCount > 0) $messages[] = "{$putInQueueForServerCount} site(s) mis en attente d'un worker libre.";
                            if ($skippedCount > 0) $messages[] = "{$skippedCount} site(s) ignorés.";
                            Notification::make()->title('Actions en masse initiées')->body(implode(' ', $messages))->success()->send();
                        }),
                ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    // NE PAS assigner user_id ici pour qu'il soit NULL par défaut
                    // $data['user_id'] = Auth::id(); // COMMENTER ou SUPPRIMER
                    // status_api sera NULL aussi si la colonne BDD le permet et qu'on ne le définit pas
                    return $data;
                })
            ]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}