# Translarr

AI-powered web UI to auto-translate subtitles from English to Spanish using the DeepSeek API. Built for the *arr stack and media servers.

## Features

- 🔍 Automatic library scanning with English subtitle detection
- 🌐 Automatic translation after scan, configurable per content type (movies/series)
- 📊 Live translation progress (parts N/M) with background tasks panel
- 🗂️ Sonarr / Radarr integration for series and movie metadata
- 📺 Emby / Jellyfin library refresh after translation
- ⏰ Scheduled scans via the built-in background worker
- 💾 SQLite storage — no external database required

## Quick Start

```bash
docker run -d \
  --name translarr \
  -p 4646:80 \
  -v ./config:/config \
  -v /path/to/movies:/movies \
  -v /path/to/tvshows:/tv \
  -e TZ=America/Lima \
  --restart unless-stopped \
  itapiaz/translarr:latest
```

Then open **http://localhost:4646**

## First Login

Sign in with the default credentials:

| Username | Password |
|---|---|
| `admin` | `admin` |

> ⚠️ **Change the password immediately** in *Configuración → Nueva Contraseña del Administrador*.

## Docker Compose

```yaml
services:
  translarr:
    image: itapiaz/translarr:latest
    container_name: translarr
    environment:
      - TZ=America/Lima
    volumes:
      - ./config:/config        # settings + SQLite database (persistent)
      - /path/to/movies:/movies # your movie library
      - /path/to/tvshows:/tv    # your TV library
    ports:
      - "4646:80"
    restart: unless-stopped
```

## Volumes

| Container path | Purpose |
|---|---|
| `/config` | Settings, SQLite database and logs. **Always mount this.** |
| `/movies` | Movie library (needs write access to save `.es.srt` files) |
| `/tv` | TV library (needs write access to save `.es.srt` files) |

> **Note:** media paths inside the container must match the paths reported by Sonarr/Radarr, or be remapped in Settings → Remote Paths.

## Tags & Architectures

| Tag | Description |
|---|---|
| `latest` | Most recent stable release |
| `2`, `2.0`, `2.0.0` | Semantic version tags |

Architectures: `linux/amd64`, `linux/arm64` (Raspberry Pi, ARM NAS).

## Upgrading from 1.x

Version 2.0 ships a new internal stack (lightweight PHP built-in server instead of nginx/LinuxServer.io base):

- The container no longer listens on port `443` internally — map only port `80`.
- `PUID`/`PGID` are no longer used; the container runs as root.
- Your `/config` volume is fully compatible — settings and history are preserved.

## Development

```bash
docker compose -f docker-compose.dev.yml up -d
```

Builds locally and mounts `./html` as a volume for hot reload.

## Release Process (maintainers)

```bash
git tag v2.1.0
git push origin v2.1.0
```

GitHub Actions builds and pushes multi-arch images to Docker Hub with tags `2.1.0`, `2.1`, `2` and `latest`, and syncs this README to the Docker Hub overview.
