# MirrorPC

MirrorPC trasforma iPad, Android o un altro computer in un display wireless del PC tramite WebRTC.

## Avvio rapido

1. Fai doppio clic su `Avvia MirrorPC.bat`.
2. Nel browser del PC premi **Avvia condivisione** e scegli lo schermo.
3. Scansiona il QR con il tablet collegato alla stessa rete Wi-Fi.
4. Sul tablet usa **Schermo intero** e ruotalo in orizzontale.

La prima esecuzione installa automaticamente tre dipendenze Node.js. Windows potrebbe chiedere di consentire Node.js sulla rete privata: confermare solo per **Reti private**.

## Prestazioni

- `Auto intelligente`: massimo 1080p/60 FPS e bitrate adattivo.
- `Full HD`: privilegia la nitidezza.
- `HD`: equilibrio consigliato per Wi-Fi normale.
- `Fluida`: riduce bitrate e latenza sulle reti più deboli.

Il video usa WebRTC cifrato. Il server serve esclusivamente pairing e segnalazione; non registra il contenuto.

## Modalità estesa

La duplicazione è operativa. Un vero desktop **esteso** richiede un driver monitor virtuale Windows firmato: non è incluso in questa versione per evitare installazioni kernel non sicure durante il prototipo.
