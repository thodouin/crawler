// import Echo from 'laravel-echo';
// import Pusher from 'pusher-js';

// window.Pusher = Pusher;

// console.log('Bootstrap.js est en cours d\'exécution');
// console.log('VITE_REVERB_APP_KEY:', import.meta.env.VITE_REVERB_APP_KEY);
// console.log('VITE_REVERB_HOST:', import.meta.env.VITE_REVERB_HOST);
// console.log('VITE_REVERB_PORT:', import.meta.env.VITE_REVERB_PORT);
// console.log('VITE_REVERB_SCHEME:', import.meta.env.VITE_REVERB_SCHEME);

// if (import.meta.env.VITE_REVERB_APP_KEY && import.meta.env.VITE_REVERB_HOST && import.meta.env.VITE_REVERB_PORT) {
//     window.Echo = new Echo({
//         broadcaster: 'reverb',
//         key: import.meta.env.VITE_REVERB_APP_KEY,
//         wsHost: import.meta.env.VITE_REVERB_HOST,
//         wsPort: import.meta.env.VITE_REVERB_PORT,
//         wssPort: import.meta.env.VITE_REVERB_PORT,
//         forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
//         enabledTransports: ['ws', 'wss'],
//     });
//     console.log('Laravel Echo initialisé avec la configuration Reverb.');

//     // Test d'abonnement pour débogage
//     window.Echo.channel('test-public-channel')
//         .listen('.TestEvent', (e) => {
//             console.log('TestEvent received on test-public-channel:', e);
//         });
//     console.log('Tentative d\'abonnement au canal de test public.');

// } else {
//     console.error('Variables d\'environnement VITE pour Reverb manquantes ou incorrectes. Laravel Echo NON initialisé.');
// }