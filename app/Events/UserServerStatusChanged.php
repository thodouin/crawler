<?php
namespace App\Events;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserServerStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user->withoutRelations();
    }

    public function broadcastWith(): array
    {
        $isProcessing = $this->user->current_site_id_processing !== null;
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'processing_status_label' => $isProcessing ? 'Pris' : 'Libre',
            'current_site_id_processing' => $this->user->current_site_id_processing,
        ];
    }

    public function broadcastOn(): array
    {
        return [new Channel('user-status-channel')];
    }

    public function broadcastAs(): string
    {
        return 'UserRowShouldRefresh';
    }
}