<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log; // Pour le débogage

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    $authenticatedUserId = $user ? $user->id : 'Guest (Not Authenticated for broadcast auth)';
    Log::info("[Broadcast Auth] Channel: App.Models.User.{$id}, Authenticated User: {$authenticatedUserId}, Requested ID: {$id}");
    if (!$user) return false; // Important : refuser si pas d'utilisateur authentifié pour la requête /broadcasting/auth
    return (int) $user->id === (int) $id;
});

// Vos autres canaux publics (ceux-ci ne passent pas par /broadcasting/auth s'ils sont appelés avec Echo.channel())
Broadcast::channel('sites-updates', function () {
    return true;
});

Broadcast::channel('users-status-updates', function () {
    return true;
});

// Broadcast::channel('App.Admin', function () { // Ce nom peut prêter à confusion s'il est appelé en privé
//     return true; 
// });