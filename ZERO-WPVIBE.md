# Yoganostress — controllo senza WPVibe

## Regola permanente

WPVibe non è un canale primario e non deve mai essere un single point of failure.

Ordine obbligatorio dei canali:
1. Yoganostress Control Bridge (GitHub manifest -> WordPress)
2. WordPress REST diretto con credenziale dedicata/revocabile, quando configurato
3. WP-CLI / accesso host
4. WPVibe solo emergenza

## Control Bridge

Il bridge è già attivo in WordPress tramite Code Snippets.
Manifest operativo: `command.json` sul branch `yoganostress-control`.

Operazioni già consentite:
- replace_post_text
- set_draft
- trash_post
- redirect_set
- redirect_remove
- media_trash
- cache_flush

Il bridge applica solo operazioni in allowlist, mantiene una revisione monotona e registra l'esito in WordPress.

## Regole di sicurezza

- costo fisso: EUR 0
- niente SQL per le normali modifiche
- niente credenziali nei manifest GitHub
- modifiche reversibili quando possibile
- pagine strategiche protette dalle operazioni distruttive
- ogni nuova revisione deve avere id univoci e verifica live dopo l'applicazione
- non dichiarare PASS o LIVE senza readback pubblico

## Assistente Yoganostress

La homepage è stata aggiornata con revision 11 senza usare WPVibe.
Titolo pubblico: “Cosa stai cercando per il tuo benessere?”
Funnel: bisogno -> risposta/orientamento -> Metodo Yoganostress -> percorso/Prima Esperienza.

WPVibe può essere usato solo se Control Bridge, REST diretto e WP-CLI risultano tutti indisponibili.
