# Componenti open source di MirrorPC Extend

MirrorPC può utilizzare **Virtual Display Driver** di VirtualDrivers per creare il display aggiuntivo riconosciuto da Windows.

- Progetto: https://github.com/VirtualDrivers/Virtual-Display-Driver
- Pacchetto Winget: `VirtualDrivers.Virtual-Display-Driver`
- Licenza: MIT

Il driver non è incluso né modificato nel repository MirrorPC. Il setup avvia Winget, che scarica e installa il pacchetto pubblicato dal relativo manutentore. L'utente deve confermare esplicitamente l'installazione.

Il progetto WinPad è stato consultato come riferimento architetturale per cattura, codifica WebRTC e profili iPad, ma il suo codice non è incluso in questa versione.
