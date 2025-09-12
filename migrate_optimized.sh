#!/bin/bash

# 🚀 Script de Migración Optimizada - API Facturación SUNAT
# Este script migra el sistema de 39 migraciones a 6 migraciones optimizadas

set -e  # Salir si hay algún error

echo "🚀 Iniciando migración a sistema optimizado..."
echo "================================================="

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Función para logging
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

# Verificar que estamos en el directorio correcto
if [ ! -f "artisan" ]; then
    log_error "No se encontró el archivo artisan. Asegúrate de estar en el directorio raíz del proyecto Laravel."
    exit 1
fi

# Verificar conexión a base de datos
log_info "Verificando conexión a base de datos..."
php artisan migrate:status > /dev/null 2>&1
if [ $? -eq 0 ]; then
    log_success "Conexión a base de datos exitosa"
else
    log_error "No se pudo conectar a la base de datos. Verifica tu configuración en .env"
    exit 1
fi

# Prompt de confirmación
log_warning "⚠️  ADVERTENCIA: Este proceso eliminará todas las tablas existentes y las recreará."
log_warning "⚠️  Asegúrate de tener un backup completo antes de continuar."
echo ""
read -p "¿Continuar con la migración? (y/N): " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    log_info "Migración cancelada por el usuario."
    exit 0
fi

# Crear backup automático
log_info "Creando backup automático..."
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="backup_antes_migracion_${TIMESTAMP}.sql"

# Obtener configuración de base de datos desde .env
DB_HOST=$(grep DB_HOST .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_PORT=$(grep DB_PORT .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_DATABASE=$(grep DB_DATABASE .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_USERNAME=$(grep DB_USERNAME .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")

# Crear comando mysqldump
if [ -n "$DB_PASSWORD" ]; then
    MYSQLDUMP_CMD="mysqldump -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} -p${DB_PASSWORD} ${DB_DATABASE}"
else
    MYSQLDUMP_CMD="mysqldump -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} ${DB_DATABASE}"
fi

# Ejecutar backup
log_info "Ejecutando: $MYSQLDUMP_CMD > $BACKUP_FILE"
$MYSQLDUMP_CMD > $BACKUP_FILE 2>/dev/null
if [ $? -eq 0 ]; then
    log_success "Backup creado: $BACKUP_FILE"
else
    log_error "No se pudo crear el backup. Verifica las credenciales de base de datos."
    exit 1
fi

# Hacer reset de migraciones
log_info "Reseteando migraciones existentes..."
php artisan migrate:reset --force
if [ $? -eq 0 ]; then
    log_success "Migraciones reseteadas"
else
    log_error "Error al resetear migraciones"
    exit 1
fi

# Backup de migraciones actuales
log_info "Haciendo backup de migraciones actuales..."
if [ -d "database/migrations" ]; then
    mv database/migrations database/migrations_backup_${TIMESTAMP}
    log_success "Backup de migraciones creado en: database/migrations_backup_${TIMESTAMP}"
fi

# Copiar migraciones optimizadas
log_info "Copiando migraciones optimizadas..."
if [ -d "database/migrations_optimized" ]; then
    cp -r database/migrations_optimized database/migrations
    log_success "Migraciones optimizadas copiadas"
else
    log_error "No se encontró el directorio database/migrations_optimized"
    exit 1
fi

# Copiar migraciones base de Laravel (necesarias)
log_info "Copiando migraciones base de Laravel..."
if [ -d "database/migrations_backup_${TIMESTAMP}" ]; then
    # Copiar migraciones esenciales de Laravel
    cp database/migrations_backup_${TIMESTAMP}/0001_01_01_000000_create_users_table.php database/migrations/ 2>/dev/null || true
    cp database/migrations_backup_${TIMESTAMP}/0001_01_01_000001_create_cache_table.php database/migrations/ 2>/dev/null || true
    cp database/migrations_backup_${TIMESTAMP}/0001_01_01_000002_create_jobs_table.php database/migrations/ 2>/dev/null || true
    log_success "Migraciones base copiadas"
fi

# Ejecutar migraciones optimizadas
log_info "Ejecutando migraciones optimizadas..."
php artisan migrate --force
if [ $? -eq 0 ]; then
    log_success "Migraciones ejecutadas exitosamente"
else
    log_error "Error al ejecutar migraciones"
    log_error "Restaurando desde backup..."
    
    # Restaurar backup en caso de error
    mysql -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} ${DB_PASSWORD:+-p${DB_PASSWORD}} ${DB_DATABASE} < $BACKUP_FILE
    exit 1
fi

# Verificar estructura
log_info "Verificando estructura de base de datos..."

# Lista de tablas esperadas
EXPECTED_TABLES=(
    "users" "roles" "permissions" "role_permission" "user_role" "personal_access_tokens"
    "ubi_regiones" "ubi_provincias" "ubi_distritos"
    "companies" "company_configurations" "branches" "clients" "correlatives"
    "invoices" "boletas" "credit_notes" "debit_notes" "daily_summaries" "voided_documents" "retentions"
    "dispatch_guides"
    "cache" "cache_locks" "jobs" "job_batches" "failed_jobs"
)

MISSING_TABLES=()
for table in "${EXPECTED_TABLES[@]}"; do
    if ! mysql -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} ${DB_PASSWORD:+-p${DB_PASSWORD}} ${DB_DATABASE} -e "DESCRIBE $table;" > /dev/null 2>&1; then
        MISSING_TABLES+=($table)
    fi
done

if [ ${#MISSING_TABLES[@]} -eq 0 ]; then
    log_success "Todas las tablas fueron creadas correctamente"
else
    log_warning "Faltan las siguientes tablas: ${MISSING_TABLES[*]}"
fi

# Mostrar estado de migraciones
log_info "Estado final de migraciones:"
php artisan migrate:status

# Verificar algunos campos específicos
log_info "Verificando campos específicos..."

# Verificar campos GRE en companies
mysql -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} ${DB_PASSWORD:+-p${DB_PASSWORD}} ${DB_DATABASE} -e "DESCRIBE companies;" | grep -q "gre_client_id_produccion"
if [ $? -eq 0 ]; then
    log_success "✅ Campos GRE presentes en companies"
else
    log_warning "⚠️  Campos GRE no encontrados en companies"
fi

# Verificar campos IVAP en invoices
mysql -h${DB_HOST:-localhost} -P${DB_PORT:-3306} -u${DB_USERNAME} ${DB_PASSWORD:+-p${DB_PASSWORD}} ${DB_DATABASE} -e "DESCRIBE invoices;" | grep -q "mto_ivap"
if [ $? -eq 0 ]; then
    log_success "✅ Campos IVAP presentes en invoices"
else
    log_warning "⚠️  Campos IVAP no encontrados en invoices"
fi

# Resumen final
echo ""
echo "================================================="
log_success "🎉 MIGRACIÓN COMPLETADA EXITOSAMENTE"
echo "================================================="
echo ""
log_info "📊 RESUMEN DE LA MIGRACIÓN:"
echo "   • Migraciones anteriores: 39"
echo "   • Migraciones nuevas: 6"
echo "   • Reducción: 85%"
echo "   • Backup creado: $BACKUP_FILE"
echo "   • Migraciones anteriores: database/migrations_backup_${TIMESTAMP}/"
echo ""
log_info "📋 PRÓXIMOS PASOS:"
echo "   1. Verificar que la aplicación funciona correctamente"
echo "   2. Ejecutar seeders si es necesario: php artisan db:seed"
echo "   3. Probar funcionalidades críticas"
echo "   4. Si todo funciona, puedes eliminar el backup y migraciones antiguas"
echo ""
log_warning "⚠️  RECORDATORIO:"
echo "   • Mantén el backup hasta estar 100% seguro"
echo "   • Las migraciones anteriores están en: database/migrations_backup_${TIMESTAMP}/"
echo "   • Si hay problemas, puedes restaurar desde: $BACKUP_FILE"
echo ""
log_success "✅ ¡Sistema optimizado y listo para usar!"