#!/bin/bash

# =============================================================================
# Script de Deployment - API Facturación SUNAT
# =============================================================================
# Este script automatiza el proceso de deployment para producción y beta
# Uso: ./deploy.sh [production|beta]
# =============================================================================

set -e  # Salir si cualquier comando falla

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuración
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENVIRONMENT=${1:-production}

# Funciones de utilidad
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Verificar argumentos
if [[ "$ENVIRONMENT" != "production" && "$ENVIRONMENT" != "beta" ]]; then
    log_error "Ambiente inválido. Usar: production o beta"
    exit 1
fi

log_info "Iniciando deployment para ambiente: $ENVIRONMENT"
log_info "Directorio del proyecto: $PROJECT_DIR"

# =============================================================================
# 1. VERIFICACIONES PREVIAS
# =============================================================================
log_info "Verificando requisitos previos..."

# Verificar si estamos en el directorio correcto
if [[ ! -f "$PROJECT_DIR/composer.json" ]]; then
    log_error "No se encontró composer.json. ¿Estás en el directorio correcto?"
    exit 1
fi

# Verificar git status
if [[ -d ".git" ]]; then
    if ! git diff --quiet; then
        log_warning "Hay cambios sin commitear en el repositorio"
        read -p "¿Continuar con el deployment? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log_info "Deployment cancelado"
            exit 1
        fi
    fi
fi

# =============================================================================
# 2. CONFIGURAR ARCHIVO .env
# =============================================================================
log_info "Configurando archivo de entorno..."

if [[ "$ENVIRONMENT" == "beta" ]]; then
    if [[ -f ".env.beta" ]]; then
        cp .env.beta .env
        log_success "Archivo .env configurado para BETA"
    else
        log_error "No se encontró archivo .env.beta"
        exit 1
    fi
else
    if [[ -f ".env.example" ]]; then
        if [[ ! -f ".env" ]]; then
            cp .env.example .env
            log_warning "Archivo .env creado desde .env.example"
            log_warning "¡CONFIGURAR VALORES DE PRODUCCIÓN ANTES DE CONTINUAR!"
            exit 1
        else
            log_info "Usando archivo .env existente"
        fi
    else
        log_error "No se encontró archivo .env.example"
        exit 1
    fi
fi

# =============================================================================
# 3. INSTALAR DEPENDENCIAS
# =============================================================================
log_info "Instalando dependencias de Composer..."
composer install --optimize-autoloader --no-dev --quiet

log_info "Instalando dependencias de NPM..."
npm ci --silent

# =============================================================================
# 4. LIMPIAR CACHÉ Y GENERAR ARCHIVOS
# =============================================================================
log_info "Limpiando caché y generando archivos..."

# Limpiar caches existentes
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Generar APP_KEY si no existe
if ! grep -q "APP_KEY=" .env || grep -q "APP_KEY=$" .env; then
    log_info "Generando APP_KEY..."
    php artisan key:generate --force
fi

# Generar caches de producción
log_info "Generando caches de producción..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# =============================================================================
# 5. CONFIGURAR BASE DE DATOS
# =============================================================================
log_info "Configurando base de datos..."

# Verificar conexión a la base de datos
if ! php artisan migrate:status &>/dev/null; then
    log_error "No se puede conectar a la base de datos. Verificar configuración."
    exit 1
fi

# Ejecutar migraciones
log_info "Ejecutando migraciones..."
php artisan migrate --force

# Ejecutar seeder de producción
log_info "Ejecutando seeder de producción..."
php artisan db:seed --class=ProductionSeeder --force

# =============================================================================
# 6. OPTIMIZAR APLICACIÓN
# =============================================================================
log_info "Optimizando aplicación..."

# Generar archivos optimizados
php artisan optimize

# Construir assets frontend
log_info "Construyendo assets frontend..."
npm run build

# =============================================================================
# 7. CONFIGURAR PERMISOS Y SEGURIDAD
# =============================================================================
log_info "Configurando permisos de archivos..."

# Establecer permisos correctos
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage/logs
chmod -R 775 storage/framework/sessions
chmod -R 775 storage/framework/views
chmod -R 775 storage/framework/cache

# Crear directorios necesarios si no existen
mkdir -p storage/app/sunat/xml
mkdir -p storage/app/sunat/pdf
mkdir -p storage/app/sunat/cdr
mkdir -p storage/certificates

# Establecer permisos para certificados
if [[ -d "storage/certificates" ]]; then
    chmod -R 600 storage/certificates
fi

# =============================================================================
# 8. VERIFICACIONES FINALES
# =============================================================================
log_info "Ejecutando verificaciones finales..."

# Verificar configuración
log_info "Verificando configuración..."
php artisan config:show app.env app.debug app.url

# Verificar conexión a base de datos
log_info "Verificando conexión a base de datos..."
php artisan migrate:status | head -5

# Verificar que no hay datos de prueba
log_info "Verificando que no existan datos de prueba..."
TEST_USERS=$(php artisan tinker --execute="echo App\\Models\\User::where('email', 'LIKE', '%@sunatapi.com')->count();")
if [[ "$TEST_USERS" -gt 0 ]]; then
    log_warning "Se encontraron $TEST_USERS usuarios de prueba en la base de datos"
fi

# Verificar archivos de debug
DEBUG_FILES=$(find . -name "*.log" -o -name "*.tmp" | wc -l)
if [[ "$DEBUG_FILES" -gt 0 ]]; then
    log_warning "Se encontraron $DEBUG_FILES archivos de debug/temporales"
fi

# =============================================================================
# 9. RESUMEN FINAL
# =============================================================================
log_success "==============================================================================="
log_success "DEPLOYMENT COMPLETADO EXITOSAMENTE"
log_success "==============================================================================="
log_success "Ambiente: $ENVIRONMENT"
log_success "Proyecto: $(php artisan tinker --execute='echo config("app.name");')"
log_success "Versión PHP: $(php -v | head -1)"
log_success "Laravel: $(php artisan --version)"
log_success "==============================================================================="

log_info "Próximos pasos:"
echo "1. Verificar configuración de certificados SUNAT"
echo "2. Configurar servidor web (Apache/Nginx)"
echo "3. Configurar SSL/TLS"
echo "4. Crear primer usuario administrador"
echo "5. Configurar tareas cron si es necesario"
echo "6. Realizar pruebas de conectividad con SUNAT"

if [[ "$ENVIRONMENT" == "production" ]]; then
    log_warning "¡IMPORTANTE! Antes de poner en producción:"
    echo "- Cambiar todas las credenciales por defecto"
    echo "- Configurar certificados SUNAT válidos"
    echo "- Verificar configuraciones de seguridad"
    echo "- Realizar backup de la base de datos"
fi

log_success "Deployment finalizado exitosamente ✓"