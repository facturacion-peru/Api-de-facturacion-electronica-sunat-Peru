# API de facturación electrónica SUNAT (SaaS)

API REST multiempresa para pequeñas empresas del Perú: empresas, usuarios, roles, inventario, tickets internos y comprobantes electrónicos SUNAT. La consume el [frontend Vue](../frontend-api-facturacion-electronica-sunat).

El desarrollo sigue **Spec-Driven Development**. Principios, specs y decisiones viven en [`../docs`](../docs), empezando por la [constitución](../docs/constitution.md) y el [índice de specs](../docs/specs/README.md).

## Estado

| Spec | Qué cubre | Estado |
|---|---|---|
| [001](../docs/specs/001-empresa-usuarios-aislamiento/spec.md) | Empresas, usuarios, roles, aislamiento multiempresa y auditoría | Implementada |
| [002](../docs/specs/002-productos-inventario/spec.md) | Productos, lotes, movimientos de inventario y alertas | Implementada |
| [003](../docs/specs/003-tickets-venta/spec.md) | Tickets de venta internos (no tributarios) | Implementada |
| 004–005 | Configuración SUNAT, emisión en beta | En aclaración |

El código del proyecto anterior (emisión con Greenter, PDF, notas, guías) está en [`legacy/`](legacy), fuera del autoload. Se reincorpora con pruebas en la spec 005; ver la [evaluación](../docs/investigacion/001-evaluacion-api-existente.md).

## Stack

Laravel 12 · PHP 8.2+ · Sanctum (tokens Bearer) · PostgreSQL · Pest. Greenter 5.1, DomPDF y QR están instalados para la emisión (spec 005). La API no usa toolchain JavaScript.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_* (PostgreSQL) y FRONTEND_URL en .env
php artisan migrate --seed          # tablas + ubigeos (+ cuentas de prueba si APP_ENV=local)
php artisan serve                   # http://127.0.0.1:8000
```

### Cuentas de prueba (solo `APP_ENV=local`)

`DevelopmentSeeder` las crea en cada `migrate:fresh --seed`, junto con productos demo (por unidad, por peso, con vencimiento y un servicio, con sus lotes) y ventas demo (una anulada). En cualquier otro entorno se omite. Todas las cuentas usan la contraseña `Clave-demo-123`.

| Correo | Rol | Empresa |
|---|---|---|
| `plataforma@demo.test` | Administrador de la plataforma | — |
| `admin@demo.test` | Administrador de empresa | Empresa Demo S.A.C. |
| `vendedor@demo.test` | Vendedor | Empresa Demo S.A.C. |
| `inactivo@demo.test` | Vendedor desactivado | Empresa Demo S.A.C. |
| `admin@otra.test` | Administrador de empresa | Otra Empresa S.A.C. (para probar el aislamiento) |

> Nunca pongas `APP_ENV=local` en un servidor accesible desde fuera: crearía estas cuentas con una contraseña pública.

### Fuera de desarrollo

```bash
php artisan platform:create-admin   # primer administrador de la plataforma (interactivo)
php artisan company:create          # alta de una empresa e invitación a su administrador
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
| Ventas | `GET/POST tickets` · `GET tickets/{id}` · `POST tickets/{id}/void` (administrador) |
| Inventario | `GET catalogs/inventory` · `GET/POST products` · `GET/PATCH products/{id}` · `GET products/{id}/lots` · `POST products/{id}/entries` · `GET products/{id}/movements` · `POST lots/{id}/adjustments` · `POST movements/{id}/reverse` · `GET inventory/alerts` |
| Plataforma | `GET/POST platform/companies` · `GET/PATCH platform/companies/{id}` · `POST platform/companies/{id}/activate` y `/deactivate` |

Las rutas de gestión de la empresa, las escrituras de inventario, el historial y las alertas exigen el rol `company_admin`. El costo de productos y lotes solo se incluye en las respuestas al administrador.

## Inventario

- **Un solo punto de escritura:** `App\Services\InventoryService`. Cada operación bloquea la fila del producto antes de tocar los lotes, lo que serializa las escrituras del mismo producto sin *deadlocks*.
- **Salida de lotes:** FEFO si el producto controla vencimiento y FIFO si no; nunca de lotes vencidos.
- **Para las ventas (specs 003 y 005):** `consume()` debe llamarse dentro de la transacción de la venta; si no, lanza una excepción. Sin stock suficiente lanza `InsufficientStock`, que la API responde como 422 con `meta.available`.
- **Saldos:** los movimientos son inmutables, y el saldo de cada lote es una caché de la suma de sus movimientos. Los errores se corrigen con ajustes o reversiones.
- **Ventas con ticket:** `App\Services\TicketService` numera sin huecos (secuencia por empresa bloqueada en la transacción), copia precio y datos del catálogo, calcula importes con `Decimal::mul`/`round` y descuenta stock con el ticket como origen. La misma `idempotency_key` devuelve el ticket ya creado. Anular revierte sus ventas; revertirlas a mano está bloqueado. El ticket **no es comprobante de pago**: lleva siempre `legal_notice`.
- **Decimales exactos:** toda la aritmética usa `bcmath` (requisito de plataforma `ext-bcmath`). `App\Support\Decimal` redondea lo que devuelve la base, porque en SQLite `SUM()` usa coma flotante.

## Pruebas

```bash
php artisan test        # SQLite en memoria (rápido)
composer test:pgsql     # la misma suite contra PostgreSQL (base db_api_sunat_testing)
vendor/bin/pint --test  # estilo
```

Corre ambas: algunas diferencias entre SQLite y PostgreSQL (`FOR UPDATE` con agregados, restricciones CHECK, `SUM()` exacto) solo aparecen en una de ellas.

Suites:
- `tests/Unit`.
- `tests/Feature`: cada prueba va dentro de una transacción (`RefreshDatabase`).
- `tests/Integration`: sin transacción envolvente, para límites de transacción y concurrencia. La prueba de concurrencia usa `pcntl_fork` y solo corre en PostgreSQL.

## Origen

Este repositorio parte de [yorchavez9/Api-de-facturacion-electronica-sunat-Peru](https://github.com/yorchavez9/Api-de-facturacion-electronica-sunat-Peru), un proyecto de uso libre y bajo responsabilidad de quien lo usa. Su integración con Greenter, las plantillas de PDF y los datos de ubigeo se conservan como base para la emisión de comprobantes.
