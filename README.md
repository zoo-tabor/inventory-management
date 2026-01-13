# Skladový systém (Inventory Management System)

Moderní webový systém pro správu skladu a inventury pro společnosti EKOSPOL a ZOO Tábor.

## 🎯 Hlavní funkce

- **Multi-company podpora** - EKOSPOL a ZOO Tábor s vlastními tématy
- **Správa skladu** - přehled zásob, výdeje, příjmy, inventury
- **Sledování expirace** - automatické upozornění na expirující položky
- **Reporting** - spotřeba dle oddělení, zaměstnanců a položek
- **Návrhy objednávek** - automatický výpočet potřebného množství
- **Notifikace** - in-app a emailové upozornění
- **Audit log** - kompletní historie změn

## 🛠 Technologie

- **Backend:** PHP 8+
- **Databáze:** MariaDB
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Hosting:** Wedos
- **Deploy:** GitHub Actions (FTP)

## 📦 Instalace

### 1. Klonování repositáře

```bash
git clone https://github.com/your-username/inventory-management.git
cd inventory-management
```

### 2. Konfigurace prostředí

Zkopírujte `.env.example` na `.env` a vyplňte hodnoty:

```bash
cp .env.example .env
```

Editujte `.env`:

```env
DB_HOST=localhost
DB_NAME=skladovy_system
DB_USER=your_db_user
DB_PASS=your_db_password

APP_URL=https://officeo.sachovaskola.eu
APP_ENV=production
APP_DEBUG=false

MIGRATE_KEY=your_random_secret_key
```

### 3. Vytvoření databáze

```sql
CREATE DATABASE skladovy_system CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
```

### 4. Spuštění migrací

Otevřete v prohlížeči:
```
https://your-domain.com/install/migrate.php?key=your_migrate_key
```

### 5. Vytvoření prvního uživatele

Po migraci vytvořte administrátorský účet přímo v databázi:

```sql
INSERT INTO users (username, password_hash, full_name, email, role, is_active)
VALUES ('admin', '$2y$10$hash_here', 'Administrátor', 'admin@example.com', 'admin', 1);
```

Nebo použijte PHP skript pro vytvoření hesla:

```php
<?php
echo password_hash('your_password', PASSWORD_DEFAULT);
```

## 🚀 Deployment

Projekt používá GitHub Actions pro automatický deploy na Wedos FTP.

### Nastavení GitHub Secrets

V nastavení repositáře přidejte tyto secrets:

- `FTP_SERVER` - FTP server (např. ftp.wedos.cz)
- `FTP_USER` - FTP uživatelské jméno
- `FTP_PASS` - FTP heslo

Po každém push do `main` větve se projekt automaticky nahraje na server.

## 📁 Struktura projektu

```
/
├── .github/workflows/     # GitHub Actions
├── assets/               # CSS, JS, obrázky
├── classes/              # PHP třídy
├── config/               # Konfigurační soubory
├── cron/                 # Cron skripty
├── includes/             # Společné PHP includes
├── install/              # Instalační skripty a migrace
├── pages/                # Stránky aplikace
└── api/                  # API endpointy
```

## 🎨 Témata

Systém podporuje dvě barevná schémata:

- **EKOSPOL** - tmavě zelené téma
- **ZOO Tábor** - oranžové téma

Téma se přepíná automaticky podle vybrané společnosti.

## 👥 Role uživatelů

- **Admin** - plný přístup včetně správy uživatelů, nastavení
- **User** - standardní uživatel, přístup k skladovým operacím

## 🔒 Bezpečnost

- Hesla hashována pomocí `password_hash()` (bcrypt)
- Session-based autentizace
- Role-based access control
- SQL injection ochrana (prepared statements)
- XSS ochrana (escape output)
- CSRF ochrana (doporučeno implementovat)

## 📊 Databázové migrace

Migrace jsou umístěny v `install/migrations/` a pojmenovány sekvenčně:

```
001_initial_schema.php
002_seed_companies.php
003_...
```

Pro spuštění nových migrací použijte:
```
/install/migrate.php?key=your_migrate_key
```

## 🔧 Cron Jobs

Nastavte v administraci hostingu:

```bash
# Denní notifikace (7:00)
0 7 * * * /usr/bin/php /path/to/cron/daily_notifications.php
```

## 📝 Vývoj

### Konvence kódu

- PHP: PSR-12 coding standard
- Databáze: `snake_case` pro tabulky a sloupce
- CSS: BEM metodologie
- JavaScript: ES6+

### Git workflow

1. Vytvořte feature branch: `git checkout -b feature/nova-funkce`
2. Commitujte změny: `git commit -m "Přidána nová funkce"`
3. Push do GitHub: `git push origin feature/nova-funkce`
4. Vytvořte Pull Request
5. Po schválení merge do `main` (automatický deploy)

## 🐛 Hlášení chyb

Pokud najdete chybu, vytvořte issue na GitHubu s:
- Popisem problému
- Kroky k reprodukci
- Očekávané chování
- Screenshot (pokud je relevantní)

## 📄 Licence

Proprietary - Internal use only

## 👨‍💻 Autoři

Vytvořeno pro EKOSPOL a ZOO Tábor

## 🆘 Podpora

Pro technickou podporu kontaktujte administrátora systému.

---

**Verze:** 1.0
**Poslední aktualizace:** 2026-01-13
