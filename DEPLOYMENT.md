# Deployment Setup

## GitHub Secrets Configuration

To enable automatic deployment to Wedos via FTP, configure these secrets in your GitHub repository:

**Settings → Secrets and variables → Actions → New repository secret**

### Required Secrets:

| Secret Name | Value |
|-------------|-------|
| `FTP_SERVER` | `328675.w75.wedos.net` |
| `FTP_USER` | `w328675_officeo` |
| `FTP_PASS` | `x947jgj5` |

## How Auto-Deploy Works

1. Push code to `main` branch
2. GitHub Actions triggers workflow (`.github/workflows/deploy.yml`)
3. Code is automatically uploaded to Wedos FTP server
4. Changes are live at https://officeo.sachovaskola.eu

## Manual FTP Upload

If you need to upload manually:

```
Server: 328675.w75.wedos.net
Username: w328675_officeo
Password: x947jgj5
Directory: /subdom/officeo/
```

## Server .env File

**Important:** The `.env` file is NOT deployed (excluded in `.gitignore`).

You must create it manually on the server via FTP with these contents:

```env
# Database Configuration
DB_HOST=md393.wedos.net
DB_NAME=d328675_officeo
DB_USER=a328675_officeo
DB_PASS=QkadEHbv

# Application Configuration
APP_NAME=Skladový systém
APP_URL=https://officeo.sachovaskola.eu
APP_ENV=production
APP_DEBUG=false
TIMEZONE=Europe/Prague

# Security
SESSION_LIFETIME=7200
SESSION_NAME=skladovy_system
MIGRATE_KEY=sk_mig_2026_officeo_secure_key_xj8k2p
```

**Set `APP_DEBUG=false` in production!**

## Running Migrations on Server

After deployment, run migrations at:

```
https://officeo.sachovaskola.eu/install/migrate.php?key=sk_mig_2026_officeo_secure_key_xj8k2p
```

This will:
- Create all database tables
- Seed initial data (companies, categories, settings)
- Create default admin user

## Default Login Credentials

```
Username: admin
Password: admin123
```

⚠️ **IMPORTANT:** Change the admin password immediately after first login!

## Deployment Checklist

- [x] GitHub repository created
- [ ] GitHub Secrets configured (FTP credentials)
- [ ] `.env` file uploaded to server via FTP
- [ ] Database migrations run on server
- [ ] Default admin password changed
- [ ] Test login at https://officeo.sachovaskola.eu

## Troubleshooting

### Migration fails with "DB_CHARSET undefined"
- Ensure `.env` file exists on server
- Check database credentials are correct
- Clear server cache/temp files

### 404 errors on all pages
- Check `.htaccess` is uploaded
- Verify mod_rewrite is enabled on server
- Check server directory structure

### Database connection fails
- Verify database credentials in `.env`
- Check database exists on Wedos
- Test connection from server

## File Permissions

Recommended permissions:
- Directories: 755
- PHP files: 644
- .htaccess: 644
- uploads/ (if created): 755

## Security Notes

1. Never commit `.env` to git
2. Keep `APP_DEBUG=false` in production
3. Change default admin password
4. Keep `MIGRATE_KEY` secret
5. Review GitHub Actions logs regularly
