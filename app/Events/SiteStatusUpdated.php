<?php
namespace App\Events;
use App\Models\Site;
use App\Models\User;
use Illuminate\Broadcasting\Channel; // Canal public
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Site $site;

    public function __construct(Site $site)
    {
        // Charger uniquement les données nécessaires ou l'objet entier
        // withoutRelations() est bien si vous ne voulez pas charger les relations par défaut
        $this->site = $site->withoutRelations(); 
    }

    // Les données qui seront envoyées avec l'événement
    public function broadcastWith(): array
    {
        return [
            'id' => $this->site->id,
            'url' => $this->site->url, // Utile pour identifier la ligne
            'status_api_value' => $this->site->status_api?->value,
            'status_api_label' => $this->site->status_api?->getLabel(), // Si SiteStatus implémente HasLabel
            'user_id' => $this->site->user_id,
            'user_name' => User::find($this->site->user_id)?->name, // Charger le nom de l'utilisateur
            'last_sent_to_api_at_relative' => $this->site->last_sent_to_api_at 
                ? $this->site->last_sent_to_api_at->locale(config('app.locale','fr'))->diffForHumans() 
                : 'Jamais',
        ];
    }
    
    // Le canal sur lequel diffuser
    public function broadcastOn(): array
    {
        // Un canal public simple. Tous les clients abonnés à ce canal recevront l'événement.
        return [new Channel('sites-updates-channel')];
    }

    // Le nom de l'événement tel qu'il sera diffusé
    public function broadcastAs(): string
    {
        return 'SiteRowShouldRefresh'; // Nom d'événement clair et précis
    }
}