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

## Deploy automatico su Hostpoint

Il workflow `Deploy su Hostpoint` pubblica l'applicazione via SSH/rsync e non
modifica mai `data/`, `private/` o `.env` sul server. Configura nell'environment
GitHub `production` i Secrets `HOSTPOINT_HOST`, `HOSTPOINT_USER`,
`HOSTPOINT_PORT`, `HOSTPOINT_PATH` e `HOSTPOINT_SSH_KEY`.

Il deploy può essere lanciato manualmente dalla sezione Actions. Per eseguirlo
automaticamente a ogni aggiornamento di `main`, crea anche la variabile GitHub
`HOSTPOINT_DEPLOY_ENABLED` con valore `true`.
