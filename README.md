# Yoganostress Control Bridge

Branch di controllo dedicato. Non è il branch di produzione di Local Autopilot.

Sicurezza:
- nessuna password WordPress nel repository;
- WordPress legge esclusivamente command.json da questo branch;
- operazioni allowlistate e reversibili;
- niente SQL, shell, utenti, password o file arbitrari;
- revisioni monotone anti-replay;
- aggiornamento del bridge solo da questo stesso branch con SHA-256 esplicito.

Canale previsto:
ChatGPT/GitHub -> command.json -> WordPress bridge -> status REST.
