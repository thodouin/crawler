<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus; // UTILISER LE BON ENUM PARTOUT
use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use App\Services\FastApiService; // Pour l'appel direct
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder; // IMPORTANT pour le scope
use Illuminate\Support\Facades\Auth;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'Sites à Crawler';
    protected static ?string $pluralModelLabel = 'sites';
    protected static ?string $modelLabel = 'site';

    public static function getEloquentQuery(): Builder
    {
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
                    ->color(fn (SiteStatus $state): string => match ($state) { // S'assurer que $state est bien une instance de SiteStatus
                        SiteStatus::PENDING_SUBMISSION => 'gray',
                        SiteStatus::SUBMITTED_TO_API => 'info',
                        SiteStatus::PROCESSING_BY_API => 'warning',
                        SiteStatus::COMPLETED_BY_API => 'success',
                        SiteStatus::FAILED_API_SUBMISSION => 'danger',
                        SiteStatus::FAILED_PROCESSING_BY_API => 'danger',
                        default => 'gray',
                    })
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('last_sent_to_api_at')->label('Dernière Activité API')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('fastapi_job_id')->label('ID FastAPI')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status_api') // Nom de la colonne
                    ->options(SiteStatus::class) // L'Enum doit implémenter HasLabel
                    ->label('Filtrer par Statut API') // Label pour le filtre
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn(Site $record): bool => $record->status_api === SiteStatus::PENDING_SUBMISSION || $record->status_api === SiteStatus::FAILED_API_SUBMISSION),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('sendToFastApiNow')
                    ->label('Envoyer')
                    ->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->form([ // AJOUT DU FORMULAIRE À L'ACTION
                        Forms\Components\TextInput::make('max_depth')
                            ->label('Profondeur Max (Max Depth)')
                            ->helperText('0 pour la page d\'accueil seulement, 1 pour la page d\'accueil et ses liens directs, etc.')
                            ->numeric()
                            ->default(0) // Valeur par défaut pour un crawl rapide
                            ->minValue(0)
                            ->required(),
                    ])
                    ->action(function (Site $record, array $data, FastApiService $fastApiService) {
                        if ($record->status_api === SiteStatus::PENDING_SUBMISSION || $record->status_api === SiteStatus::FAILED_API_SUBMISSION) {
                            $maxDepthForBulk = (int) $data['max_depth'];
                            $success = $fastApiService->submitSiteForCrawling($record, $maxDepthForBulk); // Appel direct
                            if ($success) {
                                Notification::make()->title('Demande traitée par FastAPI.')->body('Le statut du site a été mis à jour.')->success()->send();
                            } else {
                                Notification::make()->title('Échec de la communication avec FastAPI.')->body('Vérifiez les logs et le statut du site.')->danger()->send();
                            }
                        } else {
                            Notification::make()->title('Action non permise')->body('Ce site a déjà été soumis, est en cours, ou est terminé.')->warning()->send();
                        }
                    })
                    ->visible(fn(Site $record): bool => $record->status_api === SiteStatus::PENDING_SUBMISSION || $record->status_api === SiteStatus::FAILED_API_SUBMISSION),
            ])
            ->bulkActions([ // UN SEUL BLOC bulkActions
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('sendSelectedToFastApiNow') // Appel direct
                        ->label('Envoyer Sélection (Direct)')
                        ->icon('heroicon-o-paper-airplane')->color('primary') // Couleur ajustée
                        ->requiresConfirmation()
                        ->form([ // AJOUT DU FORMULAIRE À LA BULKACTION
                            Forms\Components\TextInput::make('max_depth')
                                ->label('Profondeur Max (Max Depth) pour tous les sites sélectionnés')
                                ->helperText('Appliqué à tous les sites. 0 pour la page d\'accueil seulement.')
                                ->numeric()
                                ->default(0) // Valeur par défaut
                                ->minValue(0)
                                ->required(),
                        ])
                        ->modalHeading('Envoyer les sites sélectionnés à FastAPI')
                        ->modalDescription('Cela enverra chaque site éligible directement à FastAPI. L\'interface peut être bloquée pendant le traitement.')
                        ->action(function (EloquentCollection $records, FastApiService $fastApiService, array $data) {
                            $sentCount = 0;
                            $errorCount = 0;
                            $skippedCount = 0;
                            $maxDepthForBulk = $data['max_depth'];

                            foreach ($records as $record) {
                                if ($record->status_api === SiteStatus::PENDING_SUBMISSION || $record->status_api === SiteStatus::FAILED_API_SUBMISSION) {
                                    $success = $fastApiService->submitSiteForCrawling($record, $maxDepthForBulk); // Appel direct
                                    if ($success) { $sentCount++; }
                                    else { $errorCount++; }
                                } else {
                                    $skippedCount++;
                                }
                            }
                            $messages = [];
                            if ($sentCount > 0) $messages[] = "{$sentCount} site(s) traités par FastAPI avec max_depth={$maxDepthForBulk}.";
                            if ($errorCount > 0) $messages[] = "Échec pour {$errorCount} site(s).";
                            if ($skippedCount > 0) $messages[] = "{$skippedCount} site(s) ignorés (statut non éligible).";

                            if (empty($messages)) {
                                Notification::make()->title('Aucune action effectuée.')->body('Aucun site sélectionné n\'était éligible pour l\'envoi.')->warning()->send();
                            } else {
                                Notification::make()->title('Traitement en masse terminé')
                                    ->body(implode(' ', $messages))
                                    ->success($errorCount === 0 && $sentCount > 0)
                                    ->danger($errorCount > 0)
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Associer automatiquement le site à l'utilisateur connecté lors de la création
                        $data['user_id'] = Auth::id();
                        return $data;
                    })
                    ->after(function (Site $record) {
                        // ... votre logique after() si elle existe ...
                    }),
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