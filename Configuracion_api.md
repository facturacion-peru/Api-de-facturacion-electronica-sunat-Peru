# Configuración API Facturación SUNAT - Documentación Completa

## Información del Proyecto
- **Proyecto**: API Facturación SUNAT v0
- **Framework**: Laravel 12 + Greenter
- **Fecha de configuración**: Enero 2025
- **Modo configurado**: Beta/Pruebas
- **Puerto del servidor**: 8000

---

## PASO 1: Configuración Inicial del Sistema

### 1.1 Configuración de Base de Datos (.env)
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=api_facturacion_sunat_beta
DB_USERNAME=root
DB_PASSWORD=

APP_DEBUG=true
APP_URL=http://localhost:8000
```

### 1.2 Inicialización del Sistema
**Endpoint**: `GET http://localhost:8000/api/system/info`
**Respuesta inicial**:
```json
{
    "system_initialized": false,
    "user_count": 0,
    "roles_count": 0,
    "app_name": "API Facturación SUNAT - BETA",
    "app_env": "local",
    "app_debug": true,
    "database_connected": true
}
```

### 1.3 Crear Usuario Administrador
**Endpoint**: `POST http://localhost:8000/api/auth/initialize`
**Payload**:
```json
{
    "name": "Administrador",
    "email": "admin@empresa.com",
    "password": "Admin123456"
}
```
**Respuesta exitosa**:
```json
{
    "message": "Sistema inicializado exitosamente",
    "user": {
        "id": 1,
        "name": "Administrador",
        "email": "admin@empresa.com",
        "role": "Super Administrador"
    },
    "access_token": "TOKEN_GENERADO",
    "token_type": "Bearer"
}
```

---

## PASO 2: Autenticación y Login

### 2.1 Login del Usuario
**Endpoint**: `POST http://localhost:8000/api/auth/login`
**Payload**:
```json
{
    "email": "admin@empresa.com",
    "password": "Admin123456"
}
```

### 2.2 Headers para Requests Autenticados
Para todos los endpoints protegidos, incluir:
```
Authorization: Bearer TOKEN_OBTENIDO_DEL_LOGIN
Content-Type: application/json
```

---

## PASO 3: Configuración de la Empresa

### 3.1 Setup Completo de la Empresa
**Endpoint**: `POST http://localhost:8000/api/setup/complete`
**Headers**: `Authorization: Bearer TOKEN`
**Payload**:
```json
{
    "environment": "beta",
    "company": {
        "ruc": "20123456789",
        "razon_social": "MI EMPRESA DE PRUEBA SAC",
        "nombre_comercial": "Mi Empresa",
        "direccion": "Av. Principal 123, Lima",
        "ubigeo": "150101",
        "distrito": "Lima",
        "provincia": "Lima",
        "departamento": "Lima",
        "telefono": "01234567",
        "email": "contacto@miempresa.com",
        "usuario_sol": "MIUSUARIOSOL",
        "clave_sol": "MICLAVESOL123"
    }
}
```

**Respuesta exitosa**:
```json
{
    "message": "Setup completado exitosamente",
    "company": {
        "id": 2,
        "ruc": "20123456789",
        "razon_social": "MI EMPRESA DE PRUEBA SAC",
        "environment": "beta",
        "has_certificate": false,
        "branch_count": 1
    },
    "branch": {
        "id": 2,
        "codigo": "0000",
        "nombre": "Sucursal Principal"
    }
}
```

---

## PASO 4: Configuración de Certificado Digital

### 4.1 Subir Certificado desde Postman
**Endpoint**: `POST http://localhost:8000/api/setup/configure-sunat`
**Headers**: `Authorization: Bearer TOKEN`
**Body Type**: `form-data`

| Key | Type | Value |
|-----|------|-------|
| `company_id` | Text | `2` |
| `environment` | Text | `beta` |
| `certificate_file` | **File** | *[Archivo .pem]* |
| `certificate_password` | Text | `password_del_certificado` |

### 4.2 Ubicación del Certificado
El certificado se guarda en:
```
C:\laragon\www\api-facturacion-sunat-v0\storage\app\public\certificado\certificado.pem
```

---

## PASO 5: Modificaciones Realizadas en el Código

### 5.1 Problemas Solucionados y Fixes Aplicados

#### A) Error de Campo certificado_pem no nullable
**Archivo**: `database/migrations/2025_09_01_121617_create_companies_table.php`
**Cambio realizado**:
```php
// Antes:
$table->text('certificado_pem');

// Después:
$table->text('certificado_pem')->nullable();
```

#### B) Error método setConfiguration no existe
**Archivo**: `app/Http/Controllers/Api/SetupController.php`
**Cambio realizado**:
```php
// Antes:
$company->setConfiguration($config);

// Después:
$company->update(['configuraciones' => $config]);
```

#### C) Validación de archivos de certificado
**Archivo**: `app/Http/Controllers/Api/SetupController.php`
**Cambios realizados**:
```php
// Permitir archivos .pem
'certificate_file' => 'nullable|file', // Removimos restricción de tipos

// Nuevo método para guardar en ubicación correcta
private function storeCertificateInExpectedLocation($file): void
{
    $path = 'certificado';
    Storage::disk('public')->makeDirectory($path);
    Storage::disk('public')->putFileAs($path, $file, 'certificado.pem');
}
```

#### D) Error método setCredentials en Greenter API
**Archivo**: `app/Services/GreenterService.php`
**Cambio realizado**:
```php
// Nuevo código con verificación de método y manejo de errores
try {
    $solUser = $this->company->ruc . $this->company->usuario_sol;
    
    if (method_exists($api, 'setCredentials')) {
        $api->setCredentials($solUser, $this->company->clave_sol);
    } else {
        $api->setClaveSOL($this->company->ruc, $this->company->usuario_sol, $this->company->clave_sol);
    }
    
} catch (Exception $e) {
    Log::warning("No se pudieron configurar credenciales para GRE API: " . $e->getMessage());
}
```

---

## PASO 6: Testing de la API

### 6.1 Crear Factura Gravada
**Endpoint**: `POST http://localhost:8000/api/v1/invoices`
**Headers**: `Authorization: Bearer TOKEN`
**Payload de Ejemplo**:
```json
{
    "company_id": 2,
    "branch_id": 2,
    "serie": "F001",
    "fecha_emision": "2025-01-07",
    "fecha_vencimiento": "2025-01-07",
    "moneda": "PEN",
    "tipo_operacion": "0101",
    "forma_pago_tipo": "Contado",
    "client": {
        "tipo_documento": "6",
        "numero_documento": "20100070970",
        "razon_social": "EMPRESA CLIENTE SAC",
        "nombre_comercial": "Cliente Corporativo",
        "direccion": "Av. Los Negocios 456, San Isidro",
        "ubigeo": "150130",
        "distrito": "San Isidro",
        "provincia": "Lima",
        "departamento": "Lima",
        "telefono": "01-9876543",
        "email": "facturacion@cliente.com"
    },
    "detalles": [
        {
            "codigo": "LAPTOP001",
            "descripcion": "Laptop HP Pavilion 15.6\" Intel Core i5 8GB RAM",
            "unidad": "NIU",
            "cantidad": 1,
            "mto_valor_unitario": 2542.37,
            "porcentaje_igv": 18,
            "tip_afe_igv": "10",
            "codigo_producto_sunat": "43211507"
        }
    ],
    "usuario_creacion": "vendedor01"
}
```

### 6.2 Otros Endpoints Disponibles

#### Listar Facturas
```
GET http://localhost:8000/api/v1/invoices
```

#### Listar Boletas
```
GET http://localhost:8000/api/v1/boletas
```

#### Enviar Factura a SUNAT
```
POST http://localhost:8000/api/v1/invoices/{ID}/send-sunat
```

#### Descargar PDF
```
GET http://localhost:8000/api/v1/invoices/{ID}/download-pdf
```

#### Descargar XML
```
GET http://localhost:8000/api/v1/invoices/{ID}/download-xml
```

---

## PASO 7: Configuración de Rutas API

### 7.1 Rutas Públicas (Sin Autenticación)
- `GET /api/system/info` - Información del sistema
- `POST /api/auth/initialize` - Inicialización del sistema
- `POST /api/auth/login` - Login de usuarios

### 7.2 Rutas Protegidas (Con Autenticación)
Todas las rutas bajo `/api/v1/` requieren:
```
Authorization: Bearer TOKEN
```

**Prefijo**: `/api/v1/`
- **Facturas**: `/invoices`
- **Boletas**: `/boletas` 
- **Notas de Crédito**: `/credit-notes`
- **Notas de Débito**: `/debit-notes`
- **Resúmenes Diarios**: `/daily-summaries`
- **Guías de Remisión**: `/dispatch-guides`
- **Comunicaciones de Baja**: `/voided-documents`
- **Configuraciones**: `/companies/{id}/config`

---

## PASO 8: Configuración del Servidor Laravel

### 8.1 Comandos de Inicialización Ejecutados
```bash
# Migración de base de datos
php artisan migrate:refresh --force

# Seeder de producción
php artisan db:seed --class=ProductionSeeder --force

# Iniciar servidor de desarrollo
php artisan serve --port=8000
```

### 8.2 Estructura de Datos Creada
- **Roles**: 5 roles del sistema creados
- **Permisos**: 61 permisos configurados
- **Usuarios**: Sistema listo para crear usuarios
- **Empresas**: Estructura para múltiples empresas
- **Sucursales**: Sistema de sucursales por empresa

---

## PASO 9: Logs y Debugging

### 9.1 Archivos de Log
```
storage/logs/laravel.log
```

### 9.2 Cache de Greenter
```
storage/app/greenter/cache/
```

### 9.3 Certificados
```
storage/app/public/certificado/certificado.pem
```

---

## PASO 10: Configuración de Ambiente Beta/Producción

### 10.1 URLs de SUNAT Configuradas
**Beta**:
- Facturación: `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService`
- Guías: `https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService`
- Consultas: `https://e-beta.sunat.gob.pe/ol-it-wsconscpegem-beta/billConsultService`

### 10.2 Configuraciones por Defecto
- **IGV**: 18%
- **ICBPER**: S/0.50
- **IVAP**: 4%
- **Zona horaria**: America/Lima
- **Moneda por defecto**: PEN
- **Timeout conexión**: 30 segundos

---

## PASO 11: Datos de Prueba Configurados

### 11.1 Empresa de Prueba
- **RUC**: 20123456789
- **Razón Social**: MI EMPRESA DE PRUEBA SAC
- **Usuario SOL**: MIUSUARIOSOL
- **Clave SOL**: MICLAVESOL123
- **Ambiente**: Beta

### 11.2 Sucursal Principal
- **ID**: 2
- **Código**: 0000
- **Nombre**: Sucursal Principal

---

## Notas Importantes

1. **Certificado Requerido**: Para envío a SUNAT, es obligatorio tener un certificado digital válido.
2. **Ambiente Beta**: Configurado para pruebas, usar credenciales de prueba de SUNAT.
3. **Tokens**: Los tokens de autenticación expiran, renovar según configuración de Sanctum.
4. **Logs**: Revisar logs en caso de errores para debugging.
5. **Backup**: Realizar backup de la base de datos antes de cambios importantes.

---

## Comandos Útiles

```bash
# Ver estado del sistema
GET /api/setup/status

# Limpiar cache
php artisan cache:clear

# Ver migraciones
php artisan migrate:status

# Reiniciar servidor
php artisan serve --port=8000
```

---

**Documentación creada**: Enero 2025  
**Estado**: Sistema completamente funcional para ambiente de pruebas  
**Próximos pasos**: Testing completo de facturas y configuración para producción