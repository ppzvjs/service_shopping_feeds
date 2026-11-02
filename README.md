# Shopping Feed Generator - V1

## Installation Localhost
Env Datei erstellen
```bash
cp .env .env.local
```
Container erstellen
```bash
docker compose up -d --build
```
Composer Packete installieren
```bash
docker compose run --rm composer install
```
Localhost Datenbank erstellen
```bash
docker compose exec app php bin/console doctrine:schema:update --em mysql --force
```
Cache löschen
```bash
docker compose exec app php bin/console cache:clear
```

## Login (Keycloak)
Der Feed-Manager ist per Keycloak (OpenID Connect) geschützt. Öffentlich bleibt nur der Feed-Export `/feed/export/{id}`.

In `.env.local` eintragen:
```
KEYCLOAK_URL=https://sso.example.de      # bei Keycloak < 17 inkl. /auth
KEYCLOAK_REALM=...
KEYCLOAK_CLIENT_ID=...
KEYCLOAK_CLIENT_SECRET=...               # leer bei "public" Client
KEYCLOAK_REQUIRED_ROLE=                  # optional, z.B. feed-admin
```
Im Keycloak-Client:
- Client authentication: an (confidential) oder aus (public), Standard Flow aktiv
- Valid redirect URIs: `https://<host>/login/keycloak/check`
- Valid post logout redirect URIs: `https://<host>/logged-out`

Abmelden: `/logout`. Für rein lokale Entwicklung kann der Login mit `KEYCLOAK_ENABLED=0` abgeschaltet werden.

## GTIN/ISBN aus Cover
Fehlt im Quellfeed die GTIN, wird sie beim Import aus Cover ergänzt (Zuordnung über die SKU in `C2WART`).
Quellen der Reihe nach, die erste gültige Nummer gewinnt (Standard, ermittelt mit `cover:find-gtin --coverage`):

1. `BUAUFL.EAN` – EAN der Variante/Auflage (L_AUSG_NR + L_AUFL_NR)
2. `BUAUSGAB.EAN` – EAN der Ausgabe, **nur für Artikel ohne Varianten**
3. `BUAUSGAB.ISBN` – ISBN der Ausgabe, **nur für Artikel ohne Varianten**

Die Einschränkung verhindert, dass alle Größen/Farben eines Artikels dieselbe EAN bekommen.
Abweichende Reihenfolge per `.env.local`, z.B. `COVER_GTIN_SOURCES=BUAUFL.EAN:L_AUSG_NR+L_AUFL_NR,BUAUSGAB.ISBN:L_AUSG_NR`; `0` schaltet ab.

Prüfen:
```bash
docker compose exec app php bin/console cover:find-gtin --coverage   # Abdeckung über alle Shop-Artikel + Ergebnis der Einstellung
docker compose exec app php bin/console cover:find-gtin BU-30010176-01-PPZ
```
GTINs werden per Prüfziffer validiert, ISBN-10 wird in ISBN-13 umgerechnet, interne Nummern (EAN mit 2… am Anfang) werden verworfen.
Ist Cover beim Import nicht erreichbar, bleiben die zuletzt ergänzten Werte erhalten.

Im Google-Export: mit gültiger GTIN wird `g:gtin` ausgegeben, sonst `g:identifier_exists=no`.

Nach einem Update mit neuen Datenbankfeldern:
```bash
docker compose exec app php bin/console doctrine:schema:update --em mysql --force
```

## idealo
Jeder Feed hat zusätzlich einen idealo-Export (CSV nach idealo-Spezifikation, Stand 10/2026):
`https://<host>/feed/export/{id}/idealo.csv`

**Lieferzeit:** Steht in Cover in `C2WART.LIEFER_ZEIT` etwas, gilt dieser Wert. Für idealo werden Relativierungen wie „ca.“, „etwa“,
„voraussichtlich“ entfernt und Zeitangaben vereinheitlicht: „ca. 1 - 2 Wochen“ → „Lieferung in 1-2 Wochen“, `5-7` / „5-7 Tage“ → „Lieferung in 5-7 Werktagen“.
Sonstiger Text wird unverändert übernommen. Sonst gilt die Standard-Lieferzeit des Feeds, ohne Eintrag „Lieferung in 3-5 Werktagen“.
Der Cover-Abgleich läuft beim Import; lokal ohne Cover-Zugang mit `COVER_DELIVERY_TIME=0` abschalten.

In der Oberfläche unter „6. idealo-Einstellungen“ pflegen: Standard-Lieferzeit (optional), Versanddienstleister, Zahlungsarten mit Zuschlag
(mind. eine), optional max. Bearbeitungszeit und Versandkommentar. Preise, Versandkosten-Regeln und Blacklist gelten wie beim Google-Export –
für abweichende Regeln einfach einen eigenen Feed (gleiche Quell-URL) für idealo anlegen.

idealo ruft die Datei ca. alle 30 Minuten ab, u.a. von diesen IPs (ggf. in der Firewall freigeben):
18.159.191.4, 3.127.185.158, 3.69.214.112, 3.71.161.21, 3.72.137.26, 3.73.46.190

## Feeds automatisch aktualisieren (Cronjob)
```bash
docker compose exec app php bin/console app:feed:update
```

## Datenfeed
https://pareyshop.de/media/feeds/ppz/full-export-ppz.xml




