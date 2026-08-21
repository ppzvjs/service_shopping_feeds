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


