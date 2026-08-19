# TubeTV

TubeTV è una web app per creare un palinsesto automatico da fonti video e per
consultare cataloghi TV/IPTV su desktop, mobile e Android TV.

## Avvio locale

1. Copia `.env.example` in `.env` e inserisci le chiavi necessarie.
2. Copia `data/tubetv-data.example.json` in `data/tubetv-data.json`.
3. Esegui `npm install` e poi `npm start`.
4. Apri `http://localhost:3000`.

In produzione PHP, la cartella `data` deve essere scrivibile dal server. Le
credenziali, gli account e i file runtime in `private` e `data` sono esclusi dal
repository.
