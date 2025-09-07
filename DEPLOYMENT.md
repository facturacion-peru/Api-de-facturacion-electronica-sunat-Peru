# Guía de Deployment - API Facturación SUNAT

## Visión General

Esta API está lista para deployment en ambientes de **producción** y **beta**. El proyecto ha sido completamente limpiado de datos de prueba y optimizado para producción.

## Requisitos Previos

### Servidor
- PHP 8.1 o superior
- MySQL 8.0 o superior
- Composer 2.x
- Node.js 18.x o superior
- NPM/Yarn
- Git

### Extensiones PHP Requeridas
```bash
php -m | grep -E "(openssl|pdo|mbstring|tokenizer|xml|ctype|json|bcmath|fileinfo|gd|curl|zip)"
```

## Proceso de Deployment

### 1. Preparación del Servidor

```bash
# Clonar el repositorio
git clone <repository-url> /var/www/api-facturacion-sunat
cd /var/www/api-facturacion-sunat

# Dar permisos de ejecución al script
chmod +x deploy.sh
```

### 2. Configuración de Base de Datos

```sql
-- Crear base de datos
CREATE DATABASE api_facturacion_sunat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crear usuario (reemplazar credenciales)
CREATE USER 'api_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON api_facturacion_sunat.* TO 'api_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Deployment Automático

#### Para Producción:
```bash
./deploy.sh production
```

#### Para Beta:
```bash
./deploy.sh beta
```

### 4. Configuración Manual Post-Deployment

#### A. Configurar Variables de Entorno

Editar `.env` y configurar:

```bash
# Básico
APP_NAME="API Facturación SUNAT"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Base de datos
DB_HOST=localhost
DB_DATABASE=your_database_name
DB_USERNAME=your_username
DB_PASSWORD=your_secure_password

# Certificados SUNAT
SUNAT_CERTIFICATE_PATH=storage/certificates/prod/certificado.p12
SUNAT_CERTIFICATE_PASSWORD=your_certificate_password
SUNAT_PRIVATE_KEY_PATH=storage/certificates/prod/private.key
```

#### B. Configurar Certificados SUNAT

```bash
# Crear directorios
mkdir -p storage/certificates/prod
mkdir -p storage/certificates/beta

# Copiar certificados (reemplazar con archivos reales)
cp /path/to/your/certificate.p12 storage/certificates/prod/
cp /path/to/your/private.key storage/certificates/prod/

# Establecer permisos seguros
chmod 600 storage/certificates/prod/*
chown www-data:www-data storage/certificates/prod/*
```

#### C. Crear Usuario Administrador

```bash
# Acceder al tinker de Laravel
php artisan tinker

# Crear usuario super admin
$user = new \App\Models\User();
$user->name = 'Administrador Principal';
$user->email = 'admin@tu-empresa.com';
$user->password = 'tu_password_seguro';
$user->role_id = \App\Models\Role::where('name', 'super_admin')->first()->id;
$user->user_type = 'system';
$user->active = true;
$user->email_verified_at = now();
$user->save();
```

### 5. Configuración del Servidor Web

#### Apache (.htaccess ya incluido)
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/api-facturacion-sunat/public
    
    <Directory /var/www/api-facturacion-sunat/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/api-error.log
    CustomLog ${APACHE_LOG_DIR}/api-access.log combined
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/api-facturacion-sunat/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 6. Configuración SSL (Recomendado)

```bash
# Con Let's Encrypt
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

### 7. Configurar Cron Jobs (Opcional)

```bash
# Editar crontab
sudo crontab -e

# Agregar tareas (ejemplo)
# Limpiar logs antiguos diariamente
0 2 * * * cd /var/www/api-facturacion-sunat && php artisan log:clear --days=30

# Limpiar tokens expirados
0 3 * * * cd /var/www/api-facturacion-sunat && php artisan sanctum:prune-expired
```

## Estructura del Proyecto

```
api-facturacion-sunat/
├── app/
│   ├── Http/Controllers/Api/     # Controladores API
│   ├── Models/                   # Modelos Eloquent
│   ├── Services/                 # Servicios (GreenterService, etc.)
│   └── Traits/                   # Traits reutilizables
├── config/
│   └── sanctum.php              # Configuración de autenticación
├── database/
│   ├── migrations/              # Migraciones de BD
│   └── seeders/                 # Seeders (ProductionSeeder)
├── storage/
│   ├── app/sunat/               # Archivos generados SUNAT
│   └── certificates/            # Certificados digitales
└── routes/
    └── api.php                  # Rutas de API
```

## Endpoints Principales

### Autenticación
- `POST /api/v1/auth/login` - Iniciar sesión
- `POST /api/v1/auth/logout` - Cerrar sesión
- `POST /api/v1/auth/refresh` - Renovar token

### Documentos SUNAT
- `POST /api/v1/invoices` - Crear factura
- `POST /api/v1/boletas` - Crear boleta
- `POST /api/v1/credit-notes` - Crear nota de crédito
- `POST /api/v1/debit-notes` - Crear nota de débito
- `POST /api/v1/dispatch-guides` - Crear guía de remisión
- `POST /api/v1/daily-summaries` - Crear resumen diario

### Envío a SUNAT
- `POST /api/v1/{document-type}/{id}/send-sunat` - Enviar a SUNAT

### Archivos
- `GET /api/v1/{document-type}/{id}/xml` - Descargar XML
- `GET /api/v1/{document-type}/{id}/pdf` - Descargar PDF
- `GET /api/v1/{document-type}/{id}/cdr` - Descargar CDR

## Configuraciones de Seguridad

### Roles del Sistema
- **super_admin**: Control total del sistema
- **company_admin**: Administrador de empresa
- **company_user**: Usuario de empresa
- **api_client**: Cliente API externo
- **read_only**: Solo lectura

### Configuración Sanctum
- Tokens con prefijo `sunat_`
- Expiración configurable por tipo de token
- Verificación de IP opcional
- Logging de uso de tokens
- Rotación automática de tokens

### Configuraciones de Producción
- `APP_DEBUG=false`
- `LOG_LEVEL=error`
- `SESSION_ENCRYPT=true`
- Certificados con permisos 600
- Rate limiting habilitado

## Monitoreo y Logs

### Ubicación de Logs
```bash
# Logs de Laravel
tail -f storage/logs/laravel.log

# Logs del servidor web
tail -f /var/log/apache2/api-error.log  # Apache
tail -f /var/log/nginx/error.log        # Nginx
```

### Comandos de Mantenimiento
```bash
# Limpiar caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Verificar estado
php artisan migrate:status
php artisan queue:work --daemon  # Si usas colas

# Optimizar
php artisan optimize
php artisan config:cache
php artisan route:cache
```

## Solución de Problemas

### Problemas Comunes

1. **Error de permisos**: Verificar que `www-data` tenga permisos en `storage/` y `bootstrap/cache/`
2. **Error de certificados**: Verificar rutas y permisos de certificados SUNAT
3. **Error de base de datos**: Verificar credenciales y conexión
4. **Error 500**: Revisar logs en `storage/logs/laravel.log`

### Comandos de Diagnóstico
```bash
# Verificar configuración
php artisan config:show

# Verificar conexión BD
php artisan migrate:status

# Verificar permisos
ls -la storage/
ls -la bootstrap/cache/
```

## Backup y Recuperación

### Backup de Base de Datos
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

### Backup de Archivos
```bash
tar -czf backup_files_$(date +%Y%m%d).tar.gz storage/app/sunat/
```

## Contacto y Soporte

Para soporte técnico o problemas de deployment, contactar al equipo de desarrollo con:
- Logs del error
- Configuración del servidor  
- Pasos para reproducir el problema

---

**Nota**: Este proyecto está preparado para deployment inmediato. Todos los datos de prueba han sido removidos y las configuraciones están optimizadas para producción.