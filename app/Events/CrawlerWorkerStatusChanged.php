<?php

namespace App\Events;

use App\Models\CrawlerWorker; // Importer le modèle CrawlerWorker
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel; // Si vous voulez un canal de présence un jour
use Illuminate\Broadcasting\PrivateChannel;  // Si vous voulez un canal privé un jour
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CrawlerWorkerStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CrawlerWorker $worker; // Rendre la propriété publique pour un accès facile

    /**
     * Create a new event instance.
     *
     * @param CrawlerWorker $worker L'instance du worker qui a été mis à jour
     * @return void
     */
    public function __construct(CrawlerWorker $worker)
    {
        // Il est bon de ne charger que ce qui est nécessaire ou d'utiliser withoutRelations()
        // si le modèle worker a beaucoup de relations qui ne sont pas pertinentes pour le broadcast.
        $this->worker = $worker->withoutRelations(); 
    }

    /**
     * Les données qui seront diffusées avec l'événement.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $isProcessing = $this->worker->current_site_id_processing !== null;
        $statusLabel = $this->worker->status?->getLabel() ?? 'N/A'; // Si WorkerStatus a HasLabel
        $processingSiteUrl = null;

        if ($isProcessing && $this->worker->processingSite) { // Vérifier si la relation est chargée
            $processingSiteUrl = $this->worker->processingSite->url;
        }

        return [
            'id' => $this->worker->id, // ID du CrawlerWorker dans la BDD Laravel
            'worker_identifier' => $this->worker->worker_identifier, // L'ID unique de l'app Electron/FastAPI
            'name' => $this->worker->name,
            'status_value' => $this->worker->status?->value, // 'online_idle', 'online_busy', 'offline'
            'status_label' => $statusLabel,
            'current_site_id_processing' => $this->worker->current_site_id_processing,
            'processing_site_url' => $processingSiteUrl, // URL du site en cours de traitement
            'last_heartbeat_at_relative' => $this->worker->last_heartbeat_at
                ? $this->worker->last_heartbeat_at->locale(config('app.locale','fr'))->diffForHumans()
                : 'Jamais',
        ];
    }

    /**
     * Le nom de l'événement tel qu'il sera écouté côté client.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'CrawlerWorkerStateChanged'; // Nom d'événement clair pour JavaScript
    }

    /**
     * Le(s) canal(aux) sur le(s)quel(s) l'événement doit être diffusé.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Un canal public simple pour toutes les mises à jour de statut des workers.
        // Tous les clients (navigateurs avec Filament ouvert) abonnés à ce canal recevront les mises à jour.
        return [new Channel('crawler-workers-status')];
    }
}