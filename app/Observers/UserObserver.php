<?php

namespace App\Observers;
use App\Models\User;
use App\Events\UserServerStatusChanged;

class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('current_site_id_processing')) {
            event(new UserServerStatusChanged($user->fresh()));
        }
    }
}