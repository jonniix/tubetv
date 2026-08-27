# MirrorPC

MirrorPC trasforma iPad, Android o un altro computer in un display wireless del PC tramite WebRTC.

La dashboard Control aggiunge dispositivi salvati, rilevamento online nella LAN, Wake-on-LAN, scanner QR con codice manuale e controllo mouse/tastiera per le sessioni avviate dall'app locale Windows.

La dashboard con dispositivi e indirizzi LAN viene servita solo dall'app locale (`control.html`). Il portale pubblico non incorpora né pubblica l'elenco personale: offre esclusivamente download, duplicazione/estensione rapida e collegamento a un codice temporaneo.

## Avvio rapido

1. Fai doppio clic su `Avvia MirrorPC.bat`.
2. Nel browser del PC premi **Avvia condivisione** e scegli lo schermo.
3. Scansiona il QR con il tablet collegato alla stessa rete Wi-Fi.
4. Sul tablet usa **Schermo intero** e ruotalo in orizzontale.

## Controllo e Wake-on-LAN

- Aggiungi un PC dalla colonna **I miei PC**, specificando IP locale e MAC Ethernet.
- Il pulsante `ϟ` invia il Magic Packet dalla versione locale di MirrorPC.
- Il pulsante **Scansiona** apre la fotocamera; sui browser senza scanner nativo viene usato il decoder locale jsQR.
- Durante una sessione locale, sul ricevitore premi **Controlla** per abilitare puntatore e tastiera.
- Le API Wake-on-LAN sono limitate alla rete privata; l'iniezione input è accettata esclusivamente dall'host loopback.

La prima esecuzione installa automaticamente tre dipendenze Node.js. Windows potrebbe chiedere di consentire Node.js sulla rete privata: confermare solo per **Reti private**.

## Prestazioni

- `Auto intelligente`: massimo 1080p/60 FPS e bitrate adattivo.
- `Full HD`: privilegia la nitidezza.
- `HD`: equilibrio consigliato per Wi-Fi normale.
- `Fluida`: riduce bitrate e latenza sulle reti più deboli.

Il video usa WebRTC cifrato. Il server serve esclusivamente pairing e segnalazione; non registra il contenuto.

## Modalità estesa

1. Apri MirrorPC sul PC Windows e scegli **Estendi**.
2. Se richiesto, scarica ed esegui `Setup-MirrorPC-Estendi.bat`.
3. Il setup installa tramite Winget il pacchetto firmato `VirtualDrivers.Virtual-Display-Driver` e apre le impostazioni schermo.
4. In Windows scegli **Estendi questi schermi**.
5. Torna in MirrorPC, premi **Avvia desktop esteso** e seleziona il display virtuale nella finestra di condivisione.

Il driver non viene incorporato o installato silenziosamente. Consulta `THIRD_PARTY_NOTICES.md` per provenienza e licenza.
