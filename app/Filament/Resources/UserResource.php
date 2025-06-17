<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
// use App\Filament\Resources\UserResource\RelationManagers; // Si vous en avez
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash; // Pour le mot de passe
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth; // Pour vérifier l'utilisateur connecté

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Administration'; // Ou un autre groupe pertinent
    protected static ?string $navigationLabel = 'Utilisateurs';
    protected static ?string $pluralModelLabel = 'utilisateurs';
    protected static ?string $modelLabel = 'utilisateur';
    protected static ?int $navigationSort = 1;

    /**
     * Détermine si la ressource doit être visible dans la navigation.
     * Seuls les "super-admins" peuvent voir cette ressource.
     */
    public static function canViewAny(): bool
    {
        // Remplacez 'admin@admin.com' par l'email de votre admin
        // ou implémentez une logique de rôle/permission plus robuste.
        return Auth::check() && Auth::user()->email === 'admin@admin.com';
        // Alternative avec un rôle (si vous avez un système de rôles) :
        // return Auth::user()->hasRole('super-admin');
    }

    /**
     * Applique un scope global si nécessaire, mais pour les admins, ils voient tout.
     * Si un non-admin arrivait ici (ce qui ne devrait pas arriver avec canViewAny),
     * on pourrait vouloir scoper à leur propre profil.
     */
    public static function getEloquentQuery(): Builder
    {
        if (Auth::check() && Auth::user()->email === 'admin@admin.com') {
            return parent::getEloquentQuery(); // L'admin voit tous les utilisateurs
        }
        // Un utilisateur normal ne devrait pas atteindre cette ressource.
        // Mais par sécurité, on peut le limiter à ne voir que lui-même.
        return parent::getEloquentQuery()->where('id', Auth::id());
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true), // Unique sauf pour l'enregistrement actuel en édition
                Forms\Components\TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state)) // Hasher le mot de passe avant sauvegarde
                    ->dehydrated(fn ($state) => filled($state)) // Ne sauvegarder que si rempli (pour l'édition)
                    ->required(fn (string $context): bool => $context === 'create'), // Requis seulement à la création
                // Ajoutez ici d'autres champs si nécessaire (ex: rôles)
                // Forms\Components\Select::make('roles')
                //     ->multiple()
                //     ->relationship('roles', 'name') // Si vous avez une relation roles() sur User
                //     ->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('processing_status') // Colonne virtuelle
                    ->label('État du Serveur')
                    ->getStateUsing(function (User $record): string { // Utiliser getStateUsing pour calculer la valeur
                        if ($record->current_site_id_processing !== null) {
                            // Optionnel: Afficher plus d'infos si on veut savoir quel site il traite
                            // $siteBeingProcessed = Site::find($record->current_site_id_processing);
                            // return "Pris (Site ID: {$record->current_site_id_processing}" . ($siteBeingProcessed ? " - {$siteBeingProcessed->url}" : "") . ")";
                            return "Pris";
                        }
                        return "Libre";
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Pris' => 'warning',
                        'Libre' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label('Modifié le')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Vous pouvez ajouter des filtres ici (ex: par date de création, par statut de vérification)
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // Empêcher l'admin de se supprimer lui-même
                    ->visible(fn (User $record): bool => $record->id !== Auth::id() || Auth::user()->email !== 'admin@admin.com'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // Vous pourriez vouloir ajouter une condition ici pour ne pas permettre la suppression en masse de l'admin
                        ->action(function (EloquentCollection $records) {
                            $adminEmail = 'admin@admin.com';
                            $currentUser = Auth::user();
                            $containsSelfAdmin = $records->contains(fn ($record) => $record->id === $currentUser->id && $currentUser->email === $adminEmail);

                            if ($containsSelfAdmin) {
                                Notification::make()
                                    ->title('Action non permise')
                                    ->body('Vous ne pouvez pas vous supprimer vous-même en tant qu\'administrateur via une action groupée.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            // Procéder à la suppression des autres
                            $records->each->delete();
                            Notification::make()->title('Utilisateurs supprimés')->success()->send();
                        }),
                ]),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            // Définissez ici les RelationManagers si vous voulez voir les sites/crawl_versions de chaque utilisateur, par exemple.
            // RelationManagers\SitesRelationManager::class, // Nécessiterait de créer ce manager
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'), // Note: '/create' est plus standard
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }    
}