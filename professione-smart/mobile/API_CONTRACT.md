# Contratto app mobile → backend

## GET /api/me/payment-status
Risposta:
```json
{
  "provider": "stripe",
  "status": "active",
  "tapToPay": true,
  "locationId": "tml_..."
}
```

## POST /api/terminal/token
Nessun connectedAccountId accettato dal client.

Risposta:
```json
{ "secret": "pst_..." }
```

## POST /api/terminal/payment
Richiesta:
```json
{ "amountCents": 10000 }
```

Risposta:
```json
{
  "paymentIntentId": "pi_...",
  "clientSecret": "pi_..._secret_...",
  "amountCents": 10000,
  "applicationFeeCents": 50
}
```

La fee e l'account Stripe sono determinati dal server.
