# API de facturación electrónica SUNAT (SaaS)

API REST multiempresa para pequeñas empresas del Perú: empresas, usuarios, roles, inventario, tickets internos y comprobantes electrónicos SUNAT. La consume el [frontend Vue](../frontend-api-facturacion-electronica-sunat).

El desarrollo sigue **Spec-Driven Development**. Principios, specs y decisiones viven en [`../docs`](../docs), empezando por la [constitución](../docs/constitution.md) y el [índice de specs](../docs/specs/README.md).

## Estado

| Spec | Qué cubre | Estado |
|---|---|---|
| [001](../docs/specs/001-empresa-usuarios-aislamiento/spec.md) | Empresas, usuarios, roles, aislamiento multiempresa y auditoría | Implementada |
| 002–005 | Inventario, tickets, configuración SUNAT, emisión en beta | En aclaración |

El código del proyecto anterior (emisión con Greenter, PDF, notas, guías) está en [`legacy/`](legacy), fuera del autoload. Se reincorpora con pruebas en la spec 005; ver la [evaluación](../docs/investigacion/001-evaluacion-api-existente.md).

## Stack

Laravel 12 · PHP 8.2+ · Sanctum (tokens Bearer) · PostgreSQL · Pest. Greenter 5.1, DomPDF y QR están instalados para la emisión (spec 005). La API no usa toolchain JavaScript.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_* (PostgreSQL) y FRONTEND_URL en .env
php artisan migrate --seed          # tablas + ubigeos oficiales
php artisan platform:create-admin   # primer administrador de la plataforma (interactivo)
php artisan company:create          # alta de una empresa e invitación a su administrador
php artisan serve                   # http://127.0.0.1:8000
```

En desarrollo el correo va al log (`MAIL_MAILER=log`): los enlaces de invitación y de recuperación aparecen en `storage/logs/laravel.log`. `company:create` también muestra el enlace de invitación en consola.

## Variables de entorno propias

| Variable | Descripción |
|---|---|
| `FRONTEND_URL` | Base de los enlaces de invitación (`/invitacion/{token}`) y recuperación (`/restablecer`). Por defecto `http://localhost:5173` |
| `CORS_ALLOWED_ORIGINS` | Orígenes permitidos, separados por comas. Vacío = `FRONTEND_URL`. Nunca `*` |
| `SANCTUM_EXPIRATION` | Minutos de vida del token (1440 = 24 h) |

## Seguridad y aislamiento

- **Multiempresa (principio VIII).** Todo modelo de empresa usa el trait `App\Tenancy\BelongsToCompany`. Leer sin contexto de empresa lanza `TenantContextMissing`, y escribir en otra empresa lanza `TenantMismatch`. Un recurso de otra empresa responde 404, igual que uno inexistente. `withoutTenancy()` es la única salida, solo para código de plataforma.
- **Rutas por grupo** (`routes/api.php`):
  - Públicas: solo los flujos de autenticación, con límite de intentos.
  - Empresa: `auth:sanctum` + `tenant`.
  - Plataforma: `auth:sanctum` + `platform.admin`.
- **Toda ruta de empresa nueva** debe declararse en `tests/Feature/Tenancy/TenantRoutes.php`; si no, la suite falla.
- **Auditoría** inmutable vía `App\Audit\AuditLogger`, que elimina contraseñas y secretos antes de guardar.
- **Errores** con el mismo formato en todo `api/*`: `{ success: false, message, errors? }`.

## Endpoints (`/api/v1`)

Contrato completo en `public/openapi.json` (`php artisan openapi:generate`) y documentación interactiva en `/docs`.

| Grupo | Endpoints |
|---|---|
| Público | `POST auth/login`, `auth/forgot-password`, `auth/reset-password` · `GET invitations/{token}` · `POST invitations/{token}/accept` |
| Sesión | `POST auth/logout` · `GET auth/me` |
| Empresa | `GET company` · `PATCH company` · `POST company/logo` · `GET users` · `PATCH users/{user}` · `GET/POST invitations` · `POST invitations/{id}/resend` · `DELETE invitations/{id}` · `GET audit-logs` · `GET ubigeos/*` |
| Plataforma | `GET/POST platform/companies` · `GET/PATCH platform/companies/{id}` · `POST platform/companies/{id}/activate` y `/deactivate` |

Las rutas de gestión de la empresa exigen el rol `company_admin`.

## Pruebas

```bash
php artisan test        # SQLite en memoria (rápido)
composer test:pgsql     # la misma suite contra PostgreSQL (base db_api_sunat_testing)
vendor/bin/pint --test  # estilo
```

Corre ambas: algunas diferencias entre SQLite y PostgreSQL (p. ej. `FOR UPDATE` con agregados) solo aparecen en PostgreSQL.

## Origen

Este repositorio parte de [yorchavez9/Api-de-facturacion-electronica-sunat-Peru](https://github.com/yorchavez9/Api-de-facturacion-electronica-sunat-Peru), un proyecto de uso libre y bajo responsabilidad de quien lo usa. Su integración con Greenter, las plantillas de PDF y los datos de ubigeo se conservan como base para la emisión de comprobantes.
