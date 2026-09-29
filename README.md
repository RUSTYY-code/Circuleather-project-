# Circuleather

Fullstack webproject voor **Circuleather**, gemaakt als onderdeel van de opleiding **Software Development (leerjaar 2)**.

Het project draait volledig in Docker: een PHP-webserver, een MariaDB-database en phpMyAdmin voor databasebeheer.

## Tech stack

| Onderdeel   | Technologie                      |
| ----------- | -------------------------------- |
| Backend     | PHP (Apache)                     |
| Database    | MariaDB                          |
| Beheer DB   | phpMyAdmin                       |
| Omgeving    | Docker & Docker Compose          |

## Projectstructuur

```
Circuleather-project-/
├── src/                 # Broncode van de applicatie (wordt naar /var/www/html gemount)
├── Dockerfile           # Build van de PHP-container
├── docker-compose.yml   # Services: php, mysql (MariaDB), phpmyadmin
├── php.ini              # PHP-configuratie
├── init.sql             # (Optioneel) database-schema en startdata
└── README.md
```

## Vereisten

- [Docker](https://www.docker.com/products/docker-desktop/)
- [Docker Compose](https://docs.docker.com/compose/) (zit standaard in Docker Desktop)
- [Git](https://git-scm.com/)

## Installatie en gebruik

1. **Clone de repository**

   ```bash
   git clone https://github.com/RUSTYY-code/Circuleather-project-.git
   cd Circuleather-project-
   ```

2. **Start de containers**

   ```bash
   docker compose up -d --build
   ```

3. **Open de applicatie**

   | Service        | URL                     |
   | -------------- | ----------------------- |
   | Website        | http://localhost        |
   | phpMyAdmin     | http://localhost:8080   |
   | MariaDB (host) | `localhost:3306`        |

4. **Stoppen**

   ```bash
   docker compose down
   ```

   Wil je ook de database-data verwijderen (volledig opnieuw beginnen)?

   ```bash
   docker compose down -v
   ```

## Database

De database draait in de service `mysql` (MariaDB). Standaard inloggegevens voor lokale ontwikkeling staan in `docker-compose.yml`:

| Instelling  | Waarde              |
| ----------- | ------------------- |
| Host        | `mysql` (binnen Docker) / `localhost` (vanaf je computer) |
| Poort       | `3306`              |
| Gebruiker   | `student`           |
| Wachtwoord  | `veiligwachtwoord`  |

> **Let op:** deze gegevens zijn alleen bedoeld voor lokale ontwikkeling. Gebruik ze nooit in een productieomgeving.

Bij het eerste opstarten wordt `init.sql` automatisch uitgevoerd (indien aanwezig) om de tabellen en startdata aan te maken. Dit gebeurt alleen als het `mysqldata`-volume nog leeg is.

### Verbinden vanuit PHP

```php
$pdo = new PDO(
    'mysql:host=mysql;dbname=<database_naam>;charset=utf8mb4',
    'student',
    'veiligwachtwoord'
);
```

## Ontwikkelen

De map `src/` is als volume gemount in de PHP-container. Wijzigingen in je code zijn daardoor direct zichtbaar in de browser, zonder de container opnieuw te bouwen.

Pas je de `Dockerfile` of `php.ini` aan? Bouw dan opnieuw:

```bash
docker compose up -d --build
```

Logs bekijken:

```bash
docker compose logs -f php
```

## Problemen oplossen

- **Poort 80 of 3306 is al in gebruik:** stop het programma dat de poort gebruikt (bijv. lokale Apache/MySQL/XAMPP), of pas de poortnummers aan in `docker-compose.yml`.
- **Database is leeg na aanpassen van `init.sql`:** `init.sql` draait alleen bij een nieuw volume. Gebruik `docker compose down -v` en start daarna opnieuw.
- **phpMyAdmin kan niet verbinden:** wacht een paar seconden na het opstarten tot MariaDB klaar is en ververs de pagina.

## Auteur

Gemaakt door [RUSTYY-code](https://github.com/RUSTYY-code) als schoolproject voor de opleiding Software Development.
