# Disaster Recovery — example.com

## Backup Overview

Automated backups are handled by `offen/docker-volume-backup:v2` running as the
`backup` service in `~/wordpress/docker-compose.yml`.

| Setting | Value |
|---------|-------|
| Schedule | Daily at 02:00 UTC |
| Retention | 7 days (both local and remote) |
| Compression | gzip |
| Filename pattern | `backup-YYYY-MM-DDTHH-MM-SS.tar.gz` |

### What is backed up

| Data | Source | Archive path |
|------|--------|--------------|
| WordPress core, plugins, themes, uploads | Docker volume `wordpress` | `/backup/wordpress/` |
| MariaDB data files + logical SQL dump | Docker volume `db` | `/backup/db/` (includes `all-databases.sql`) |
| Apache, Traefik, Datadog, MariaDB configs | `~/wordpress/config/` | `/backup/host-config/config/` |
| Let's Encrypt certificates | `~/wordpress/config/letsencrypt/` | `/backup/host-config/config/letsencrypt/` |
| WordPress env file | `~/wordpress/.env` | `/backup/host-config/env-wordpress` |
| Traefik env file | `~/wordpress/.env-traefik` | `/backup/host-config/env-traefik` |
| MU-plugins (imgproxy, asset-optimizer) | `~/wordpress/wp-content/mu-plugins/` | `/backup/host-config/mu-plugins/` |

### Backup lifecycle

1. `archive-pre` hook runs `mariadb-dump` inside the running `db` container
2. WordPress and DB containers are stopped for consistency
3. Tar archive is created from all mounted volumes
4. Containers are restarted
5. Archive is saved locally to `/home/youruser/wordpress_backups/`
6. Archive is uploaded via SFTP to the Hetzner Storage Box
7. Archives older than 7 days are pruned from both locations

### Storage locations

| Location | Path | Access |
|----------|------|--------|
| **Local** | `/home/youruser/wordpress_backups/` | Direct filesystem |
| **Hetzner Storage Box** | `your-subaccount.your-storagebox.de:/home/backups/` | SFTP on port 23, key auth |

The SSH private key for the Hetzner box is at `~/wordpress/config/backup-ssh-key`.

---

## Recovery Procedures

### A. Restore on the same server (local archive available)

```bash
cd /home/youruser/wordpress_backups

# List available local backups
ls -lh backup-*.tar.gz

# Restore from a specific archive
./backup.sh backup-YYYY-MM-DDTHH-MM-SS.tar.gz
```

### B. Restore on the same server (local archive missing, fetch from Hetzner)

```bash
cd /home/youruser/wordpress_backups

# List available remote backups
sftp -P 23 -i ~/wordpress/config/backup-ssh-key \
  your-subaccount@your-subaccount.your-storagebox.de <<< "ls -la /home/backups/"

# Download and restore
./backup.sh --remote backup-YYYY-MM-DDTHH-MM-SS.tar.gz
```

### C. Restore on a fresh server (full disaster recovery)

#### 1. Provision the server

Spin up a new instance (tested on Ubuntu 24.04 / Oracle Linux) and install Docker:

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# log out and back in
```

#### 2. Recreate the project directory

```bash
mkdir -p ~/wordpress ~/wordpress_backups
cd ~/wordpress
```

#### 3. Download the backup from Hetzner

You need the SSH private key. If you still have it from the old server, copy it
over. If not, use password auth to download:

```bash
# With key (preferred):
sftp -P 23 -i ~/wordpress/config/backup-ssh-key \
  your-subaccount@your-subaccount.your-storagebox.de:/home/backups/LATEST.tar.gz \
  ~/wordpress_backups/

# Without key (password fallback):
# Use the Hetzner Storage Box password for subaccount your-subaccount
sftp -P 23 your-subaccount@your-subaccount.your-storagebox.de:/home/backups/LATEST.tar.gz \
  ~/wordpress_backups/
```

Replace `LATEST.tar.gz` with the actual filename. To list files:

```bash
sftp -P 23 your-subaccount@your-subaccount.your-storagebox.de <<< "ls -la /home/backups/"
```

#### 4. Extract config files first

The `docker-compose.yml`, `backup.env`, and `.env` files are needed to bring
the stack up. Extract them from the archive:

```bash
cd ~/wordpress_backups
tar -xzf ARCHIVE.tar.gz backup/host-config/

# Restore config tree
cp -a backup/host-config/config/ ~/wordpress/config/
cp backup/host-config/env-wordpress ~/wordpress/.env
cp backup/host-config/env-traefik ~/wordpress/.env-traefik
mkdir -p ~/wordpress/wp-content/mu-plugins
cp -a backup/host-config/mu-plugins/ ~/wordpress/wp-content/mu-plugins/

rm -rf backup/
```

You will also need to restore or recreate `docker-compose.yml` and `backup.env`
from version control or another copy, since they are not inside the backup
archive (they are part of the project repo, not a mounted volume/config).

#### 5. Restore volumes

```bash
# Create and populate the DB volume
docker volume create wordpress_db
docker run --rm \
  -v wordpress_db:/var/lib/mysql \
  -v ~/wordpress_backups:/archive:ro \
  alpine sh -c "tar -xzf /archive/ARCHIVE.tar.gz -C / backup/db/ && cp -a /backup/db/. /var/lib/mysql/"

# Create and populate the WordPress volume
docker volume create wordpress_wordpress
docker run --rm \
  -v wordpress_wordpress:/var/www/html \
  -v ~/wordpress_backups:/archive:ro \
  alpine sh -c "tar -xzf /archive/ARCHIVE.tar.gz -C / backup/wordpress/ && cp -a /backup/wordpress/. /var/www/html/"
```

#### 6. Bring the stack up

```bash
cd ~/wordpress
docker compose up -d
```

#### 7. Post-restore checklist

- [ ] Verify the site loads at `https://example.com`
- [ ] Update DNS to point to the new server IP if it changed
- [ ] Purge Cloudflare cache (APO + full)
- [ ] Flush Redis cache: `docker compose exec redis redis-cli FLUSHALL`
- [ ] Verify the backup service is scheduled: `docker compose logs backup`
- [ ] Confirm SFTP connectivity to Hetzner: `docker compose exec backup backup` (one-off test)

---

## Triggering a manual backup

```bash
cd ~/wordpress
docker compose exec backup backup
```

This runs the full backup cycle (dump, stop, archive, restart, upload) immediately.

---

## Checking backup status

```bash
# Recent logs
docker compose logs backup --tail 50

# Local archives
ls -lh ~/wordpress_backups/backup-*.tar.gz

# Remote archives
sftp -P 23 -i ~/wordpress/config/backup-ssh-key \
  your-subaccount@your-subaccount.your-storagebox.de <<< "ls -la /home/backups/"
```

---

## Credentials reference

| Resource | Location |
|----------|----------|
| WordPress DB credentials | `~/wordpress/.env` (MYSQL_USER, MYSQL_PASSWORD) |
| Hetzner Storage Box SFTP | `your-subaccount.your-storagebox.de`, port 23, user `your-subaccount` |
| Hetzner SSH private key | `~/wordpress/config/backup-ssh-key` |
| Cloudflare API | `~/wordpress/.env` (CF_API_KEY, CF_API_EMAIL) |
| Datadog API | `~/wordpress/.env` (DD_API_KEY) |
