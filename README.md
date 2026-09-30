# API de facturación electrónica SUNAT (SaaS)

API REST multiempresa para pequeñas empresas del Perú: empresas, usuarios, roles, inventario, tickets internos y comprobantes electrónicos SUNAT (en el ambiente beta). La consume el [frontend Vue](../frontend-api-facturacion-electronica-sunat).

El desarrollo sigue **Spec-Driven Development**. Principios, specs y decisiones viven en [`../docs`](../docs), empezando por la [constitución](../docs/constitution.md) y el [índice de specs](../docs/specs/README.md).

## Estado

| Spec | Qué cubre | Estado |
|---|---|---|
| [001](../docs/specs/001-empresa-usuarios-aislamiento/spec.md) | Empresas, usuarios, roles, aislamiento multiempresa y auditoría | Implementada |
| [002](../docs/specs/002-productos-inventario/spec.md) | Productos, lotes, movimientos de inventario y alertas | Implementada |
| [003](../docs/specs/003-tickets-venta/spec.md) | Tickets de venta internos (no tributarios) | Implementada |
| [004](../docs/specs/004-configuracion-sunat/spec.md) | Configuración SUNAT segura (clave SOL y certificado cifrados) y series | Implementada |
| [005](../docs/specs/005-emision-comprobantes-beta/spec.md) | Emisión de boletas y facturas en beta, clientes, reintentos y PDF | Implementada |
| [006](../docs/specs/006-panel-plataforma/spec.md) | Panel de la plataforma: empresas, soporte de la emisión y auditoría | Implementada |

El código del proyecto anterior (emisión con Greenter, PDF, notas, guías) está en [`legacy/`](legacy), fuera del autoload. La spec 005 reescribió con pruebas lo necesario para emitir facturas y boletas; el resto (notas, guías, resumen diario) sigue allí como referencia. Ver la [evaluación](../docs/investigacion/001-evaluacion-api-existente.md).

## Stack

Laravel 12 · PHP 8.2+ · Sanctum (tokens Bearer) · PostgreSQL · Pest. Greenter 5.1 (XML UBL, firma y envío), DomPDF y endroid/qr-code para los comprobantes. Requisitos de plataforma: `ext-bcmath`, `ext-openssl` y `ext-gd`. La API no usa toolchain JavaScript.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configura DB_* (PostgreSQL) y FRONTEND_URL en .env
php artisan migrate --seed          # tablas + ubigeos (+ cuentas de prueba si APP_ENV=local)
php artisan serve                   # http://127.0.0.1:8000
php artisan schedule:work           # en otra terminal: reintentos de envío a SUNAT
```

### Cuentas de prueba (solo `APP_ENV=local`)

`DevelopmentSeeder` las crea en cada `migrate:fresh --seed`, junto con productos demo (por unidad, por peso, con vencimiento y un servicio, con sus lotes), ventas demo (una anulada), la configuración SUNAT de la empresa demo (credenciales de beta, un certificado autofirmado y las series F001 y B001), dos clientes y una boleta y una factura. El seeder valida la configuración y emite los comprobantes contra SUNAT beta; sin red, la configuración queda pendiente y no se emite nada. En cualquier otro entorno se omite. Todas las cuentas usan la contraseña `Clave-demo-123`.

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
php artisan platform:create-admin   # administradores de la plataforma (interactivo; no se crean desde el panel)
php artisan company:create          # alta de una empresa e invitación a su administrador
```

En desarrollo el correo va al log (`MAIL_MAILER=log`): los enlaces de invitación y de recuperación aparecen en `storage/logs/laravel.log`. `company:create` también muestra el enlace de invitación en consola.

## Variables de entorno propias

| Variable | Descripción |
|---|---|
| `FRONTEND_URL` | Base de los enlaces de invitación (`/invitacion/{token}`) y recuperación (`/restablecer`). Por defecto `http://localhost:5173` |
| `CORS_ALLOWED_ORIGINS` | Orígenes permitidos, separados por comas. Vacío = `FRONTEND_URL`. Nunca `*` |
| `SANCTUM_EXPIRATION` | Minutos de vida del token (1440 = 24 h) |

## Secretos de SUNAT y `APP_KEY`

La clave SOL, el certificado digital (PEM con la clave privada) y su contraseña se guardan **cifrados en la base de datos** con el cast `encrypted` de Laravel, que usa `APP_KEY`. Ninguna respuesta, log ni registro de auditoría los incluye.

- **No pierdas `APP_KEY`.** Sin ella no se pueden descifrar: habría que volver a subir el certificado y la clave SOL de cada empresa.
- **Para rotarla**, pon la clave anterior en `APP_PREVIOUS_KEYS` (separadas por comas) antes de cambiar `APP_KEY`. Laravel descifra con cualquiera de ellas y cifra con la nueva.
- En beta, los envíos usan las credenciales genéricas de prueba de SUNAT (`config/services.php`, `SUNAT_BETA_*`); la clave SOL real se guarda para producción (A-31).
- **Validar** comprueba el certificado vigente y lee el WSDL de SUNAT beta. Si SUNAT no responde devuelve 503 y la configuración no pasa a error.
- **Series:** `App\Services\SeriesService::nextNumber()` exige una transacción abierta y bloquea la fila de la serie; lo usará la emisión (spec 005). El correlativo no se edita por la API.

## Emisión de comprobantes (spec 005, solo beta)

- **Emitir:** `App\Services\SalesDocumentService::issue()` hace todo en una transacción: comprueba la configuración SUNAT validada, la serie y el cliente, toma el correlativo bloqueado, calcula con `App\Sunat\TaxCalculator` (precio con IGV → base e IGV, con `bcmath`), firma el XML (`UblBuilder` + `DocumentSigner`) y descuenta el stock. Si algo falla no queda nada, ni el número. La boleta de más de S/ 700 exige el documento del comprador y la factura, un cliente con RUC.
- **Enviar:** `App\Sunat\SunatDispatcher` envía el XML ya firmado después del commit. Un *lease* en la fila (`locked_until`) impide dos envíos a la vez.
- **Estados:** `pending` (sin respuesta definitiva) → `sent` (envío en curso) → `accepted`, `observed` o `rejected` (definitivos). Si SUNAT no responde, se reintenta a los 1, 2, 5, 10 y 30 min y luego cada hora durante 24 h; después solo queda el botón «Reintentar».
- **Reintentos automáticos:** el comando `sunat:send-pending` corre cada minuto en el *scheduler*. En el servidor hace falta el cron de Laravel (`* * * * * php artisan schedule:run`); en desarrollo, `php artisan schedule:work` en otra terminal. Sin él, los pendientes solo se envían con «Reintentar».
- **SUNAT beta limita la frecuencia:** si se envía un documento a los pocos segundos de otro, responde `HTTP 401`. Al emitir se reintenta una vez tras `SUNAT_RATE_LIMIT_PAUSE` segundos (5 por defecto).
- **PDF:** A4 y 80 mm con QR y la marca «PRUEBAS — SIN VALOR LEGAL», generados al descargar (`App\Sales\DocumentPdf`). El XML firmado y el CDR se guardan en la base de datos. Requisito de plataforma `ext-gd` para el QR.
- **Pruebas:** en la suite se usa `FakeSunatSender`; `tests/Beta/` guarda el *spike* y las pruebas contra SUNAT beta real, que no corren por defecto.

## Seguridad y aislamiento

- **Panel de la plataforma (spec 006, A-37):** lee entre empresas con `withoutTenancy()` y responde con recursos de lista blanca (`Platform*Resource`): metadatos y contadores, nunca secretos ni datos de negocio. `PlatformPrivacyTest` y `PlatformAccessTest` recorren todas las rutas `platform/*`.
- **Plataforma (spec 006, A-38):** la sesión del administrador de la plataforma vence a las 8 h y cada inicio de sesión se audita (`auth.login`). **Antes de producción** hace falta un segundo factor (TOTP) para estas cuentas (A-24).

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
| Comprobantes | `GET/POST sales-documents` · `GET sales-documents/{id}` · `POST sales-documents/{id}/retry` · `GET sales-documents/{id}/pdf?format=a4\|80mm`, `/xml`, `/cdr` · `GET/POST customers` · `PATCH customers/{id}` (administrador) |
| SUNAT | `GET sunat/status` · `GET series` (todos) · `GET sunat/settings` · `PUT sunat/credentials` · `POST sunat/certificate` · `POST sunat/validate` · `POST series` · `PATCH series/{id}` (administrador) |
| Plataforma | `GET/POST platform/companies` (con resumen, búsqueda y filtro `issues`) · `GET/PATCH platform/companies/{id}` (incluido el domicilio fiscal) · `POST platform/companies/{id}/activate` y `/deactivate` (con motivo) · `POST platform/companies/{id}/admin-invitation/resend` · `GET platform/sales-documents` · `POST platform/sales-documents/{id}/retry` · `GET platform/audit-logs` · `GET platform/ubigeos/search` |

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
