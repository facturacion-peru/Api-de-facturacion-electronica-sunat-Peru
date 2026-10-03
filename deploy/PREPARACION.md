# Preparación del despliegue (spec 009)

Lo que hace **el responsable del SaaS** antes de desplegar: cuentas, pagos, accesos y secretos. El resto (scripts, CI, configuración) está en este repositorio.

> **Regla de oro:** los repositorios son **públicos** (A-46). Ningún secreto se escribe en un archivo del repositorio, ni en un *issue*, ni en un commit. Los secretos viven solo en el gestor de contraseñas, en los secretos de GitHub Actions y en el `.env` del servidor.

## 1. Repositorios en GitHub (ahora)

1. Crear tres repositorios **públicos**: API, frontend y documentación (la raíz, con `docs/` y `AGENTS.md`).
2. Configurar el correo de los commits nuevos como `noreply` de GitHub si no quieres exponer tu correo personal (*Settings → Emails → Keep my email address private*).
3. Subir el código (el agente lo hace cuando tenga las URLs):

   ```bash
   # API: el remoto del autor original ya se llama «upstream»
   git remote add origin git@github.com:<usuario>/<repo-api>.git
   git push -u origin --all
   # Frontend y documentación: igual, con su URL
   ```
4. Activar **GitHub Actions** en los tres (los workflows de CI están en `.github/workflows/`).
5. Proteger `main`: exigir que la CI pase antes de integrar (*Settings → Branches*).

## 2. Servidor (en espera: A-46, actualización del 2026-09-30)

| Qué | Detalle |
|---|---|
| VPS | Ubuntu 24.04 LTS, 2 vCPU, 4 GB de RAM, 60 GB de disco; región cercana al Perú (p. ej. São Paulo o Miami) |
| Acceso | Tu llave SSH pública cargada al crear el VPS; sin contraseña de `root` |
| Dominio y DNS | Registros **A** hacia la IP del VPS: `app`, `api`, `staging-app`, `staging-api` |

## 3. Servicios externos (cuando se despliegue)

| Servicio | Para qué | Qué anotar (en el gestor de contraseñas) |
|---|---|---|
| Almacenamiento compatible con S3 (Backblaze B2, Cloudflare R2 o AWS S3) | Copias de seguridad (A-45) | Nombre del *bucket*, región, *endpoint*, clave de acceso y clave secreta (con permiso solo sobre ese *bucket*) |
| Correo transaccional (Amazon SES, Resend o Brevo) | Invitaciones y recuperación de contraseña (A-47) | Host SMTP, puerto, usuario y contraseña SMTP; correo remitente (`no-reply@<dominio>`) |
| DNS del correo | Que no caiga en spam | Registros **SPF**, **DKIM** (los que indique el proveedor) y **DMARC** (`v=DMARC1; p=quarantine; rua=mailto:<tu correo>`) |
| Monitor de latidos (p. ej. Healthchecks.io) | *Scheduler* y copias (A-48) | Dos URLs de *ping*: `OPS_HEARTBEAT_URL` y `OPS_BACKUP_PING_URL` |
| Monitor de disponibilidad (p. ej. UptimeRobot) | Web y API (A-48) | Monitores sobre `https://app.<dominio>` y `https://api.<dominio>/up`, con aviso a tu correo |

## 4. Secretos que se generan y dónde van

| Secreto | Cómo se genera | Dónde va |
|---|---|---|
| `APP_KEY` (uno por ambiente) | `php artisan key:generate --show` | Gestor de contraseñas **y** `.env` del servidor. **Si se pierde, los secretos SUNAT de las copias no se pueden descifrar** |
| `BACKUP_ARCHIVE_PASSWORD` | `openssl rand -base64 32` | Gestor de contraseñas **y** `.env` del servidor |
| Contraseñas de PostgreSQL (una por ambiente) | Las crea `provision.sh` | `.env` del servidor |
| Llave SSH de despliegue | `ssh-keygen -t ed25519 -f deploy_key -C github-actions` | Privada: secreto `DEPLOY_SSH_KEY` de GitHub; pública: `~deploy/.ssh/authorized_keys` del servidor |

## 5. GitHub Actions (cuando se despliegue)

La CI (`.github/workflows/ci.yml`) corre desde el primer *push*. Los trabajos de despliegue quedan **apagados** hasta crear la variable de repositorio `DEPLOY_ENABLED` con valor `true` (*Settings → Secrets and variables → Actions → Variables*). Mientras no exista el servidor, no la crees.

En el repositorio de la API y en el del frontend, crea los ambientes `staging` y `production` (*Settings → Environments*). En `production`, marca **revisor requerido**: cada despliegue a producción espera tu aprobación.

**Secretos** de cada ambiente:

| Secreto | Valor |
|---|---|
| `DEPLOY_HOST` | IP o nombre del VPS |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | Llave privada de despliegue (sección 4) |
| `DEPLOY_KNOWN_HOSTS` | Huella del servidor: `ssh-keyscan -t ed25519 <IP>`. Compárala con la que muestra la consola del proveedor; sin ella, el despliegue se niega a conectar |

**Variables** de cada ambiente (no son secretas):

| Variable | Repositorio | staging | production |
|---|---|---|---|
| `HEALTH_URL` | API | `https://staging-api.<dominio>/up` | `https://api.<dominio>/up` |
| `HEALTH_URL` | frontend | `https://staging-app.<dominio>/` | `https://app.<dominio>/` |
| `VITE_API_BASE_URL` | frontend | `https://staging-api.<dominio>` | `https://api.<dominio>` |
| `VITE_PILOT_MODE` | frontend | `true` | `true` durante el piloto (A-43) |

Orden: en cada cambio de `main`, la API pasa a staging y luego espera aprobación para producción. La web hace lo mismo, usando el `release.sh` de la API ya publicada. Si `HEALTH_URL` no responde después de publicar, la versión anterior vuelve sola.

El `.env` de cada ambiente **no** va a GitHub: se crea una vez en el servidor (`/srv/sunat/<ambiente>/api/shared/.env`) a partir de `deploy/env.<ambiente>.example`.
