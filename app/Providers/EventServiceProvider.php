<?php

namespace App\Providers;

use App\Models\User; // Importer le modèle User
use App\Observers\UserObserver; // Importer l'UserObserver
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // Vous pouvez ajouter d'autres listeners d'événements ici si nécessaire
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        // Enregistrer l'observateur pour le modèle User
        // Chaque fois qu'un User est 'updated', la méthode 'updated' de UserObserver sera appelée.
        User::observe(UserObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false; // Ou true si vous utilisez la découverte d'événements
    }
}