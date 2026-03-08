# WordPress Docker Production Stack

A production-grade WordPress stack running on Docker Compose with Traefik, Redis, MariaDB, imgproxy, Datadog, automated backups, and a WP-Cron sidecar. Designed for a single budget VPS.

This stack powers [Joy of Exploring the World](https://joyofexploringtheworld.com) — a travel and lifestyle blog running on a 24 GB VPS with free Cloudflare CDN.

## Architecture

```
Internet → Cloudflare (CDN + APO + SSL) → Traefik v3 (reverse proxy)
                                              ├── WordPress (x2, load-balanced)
                                              │     ├── Redis (object cache)
                                              │     ├── MariaDB (database)
                                              │     └── MU-plugins (imgproxy rewrite, asset optimizer)
                                              └── imgproxy (image processing, img.example.com)

Sidecar services:
  ├── wp-cron (runs due tasks every 60s)
  ├── backup (daily volume backup to remote SFTP)
  └── datadog-agent (metrics, APM, database monitoring)
```

## Services

| Service | Image | Purpose |
|---------|-------|---------|
| `wordpress` | Custom (see `Dockerfile`) | WordPress with dd-trace and phpredis baked in. Scaled to 2 instances. |
| `reverse-proxy` | `traefik:v3` | TLS termination, load balancing, HTTP→HTTPS redirect, real-IP extraction. |
| `redis` | `redis:alpine` | Object cache (2 GB maxmemory, allkeys-lru). |
| `db` | `mariadb:latest` | Database with InnoDB buffer pool tuned to 2 GB. |
| `imgproxy` | `ghcr.io/imgproxy/imgproxy` | On-the-fly image resize, WebP/AVIF conversion, HMAC-signed URLs. |
| `wp-cron` | `wordpress:cli` | Runs `wp cron event run --due-now` every 60 seconds. |
| `backup` | `offen/docker-volume-backup:v2` | Daily backup of WordPress + DB volumes to local archive and remote SFTP. |
| `datadog-agent` | `gcr.io/datadoghq/agent` | APM tracing, DogStatsD metrics, database monitoring. |
| `wpcli` | `wordpress:cli` | One-shot WP-CLI commands. |

## Quick start

```bash
cp .env.example .env
cp .env-traefik.example .env-traefik
cp backup.env.example backup.env

# Edit .env, .env-traefik, and backup.env with your actual values

docker compose up -d
```

## Configuration files

| File | What it does |
|------|--------------|
| `config/health.php` | Lightweight `/health.php` endpoint (returns 200 without loading WordPress) for container healthchecks. |
| `config/php/99-opcache.ini` | OPcache tuning: `validate_timestamps=0`, 256 MB memory. |
| `config/apache/01-static-cache-headers.conf` | Far-future `Cache-Control: immutable` headers for static assets. |
| `config/apache/02-server-status.conf` | Apache `mod_status` on a dedicated port (8888) for Datadog metrics. |
| `config/traefik/real-ip.yaml` | Traefik dynamic config: real client IP from Cloudflare + Brotli/gzip compression. |
| `config/mariadb/conf.d/98-innodb-tuning.cnf` | InnoDB buffer pool set to 2 GB (tune for your VPS RAM). |
| `config/datadog/conf.d/apache.d/conf.yaml` | Datadog Apache integration scraping `server-status` on port 8888. |

## MU-plugins

| Plugin | What it does |
|--------|--------------|
| `wp-content/mu-plugins/imgproxy-rewrite.php` | Rewrites WordPress media URLs to HMAC-signed imgproxy URLs at render time. Uses `local://` volume reads (no network hop), output-buffer fallback for FSE themes, WebP forcing for Cloudflare compatibility. |
| `wp-content/mu-plugins/asset-optimizer.php` | Front-end performance: defers scripts, async CSS via `media="print"` swap, preloads critical fonts, prunes unused `@font-face` declarations, promotes LCP image to `fetchpriority="high"`, removes unnecessary resource hints, disables emoji loader. |

## Terraform (Cloudflare)

The `terraform/` directory manages Cloudflare DNS records, www-to-apex 301 redirects, and imgproxy cache rules as code.

```bash
cd terraform
terraform init
terraform plan
terraform apply
```

## Disaster recovery

See [RECOVERY.md](RECOVERY.md) for full backup and restore procedures.

## Blog posts

This stack is documented in a series of blog posts:

- [Running a WordPress Travel Blog on a Budget VPS: The Full Stack](https://blog.nuvai.cloud/posts/wordpress-docker-compose-production-stack/)
- [Self-hosted image optimization for WordPress with imgproxy](https://blog.nuvai.cloud/posts/self-hosted-wordpress-imgproxy/)
- [How We Sped Up Our Travel Blog (Without Changing Hosts)](https://blog.nuvai.cloud/posts/how-we-sped-up-our-travel-blog/)
- [Running WordPress Cron the Right Way in Docker](https://blog.nuvai.cloud/posts/running-wordpress-cron-right-way-docker/)
- [Rank Math Sitemap Not Loading with Traefik](https://blog.nuvai.cloud/posts/rank-math-sitemap-not-loading-traefik/)
- [Cloudflare www-to-apex Redirects with Terraform](https://blog.nuvai.cloud/posts/cloudflare-www-to-apex-redirects-terraform/)
- [Automated WordPress Backups to Hetzner with Docker](https://blog.nuvai.cloud/posts/wordpress-docker-automated-backups-hetzner/)
- [Fixing 'Robots.txt Unreachable' in Google Search Console with Traefik and Docker](https://blog.nuvai.cloud/posts/fixing-robots-txt-unreachable-traefik-docker/)

## License

MIT
