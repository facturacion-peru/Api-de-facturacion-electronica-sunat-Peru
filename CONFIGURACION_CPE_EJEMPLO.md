# ⚙️ Configuración de Ejemplo - Sistema CPE v2

## 🗄️ Configuración de Base de Datos

### **1. Configurar Credenciales OAuth2 (Método Principal)**

```sql
-- Para modo PRODUCCIÓN
UPDATE companies SET 
    gre_client_id_produccion = 'tu_client_id_produccion',
    gre_client_secret_produccion = 'tu_client_secret_produccion',
    modo_produccion = true
WHERE ruc = '20123456789';

-- Para modo BETA/PRUEBAS
UPDATE companies SET 
    gre_client_id_beta = 'tu_client_id_beta',
    gre_client_secret_beta = 'tu_client_secret_beta',
    modo_produccion = false
WHERE ruc = '20123456789';
```

### **2. Configurar Credenciales SOL (Método Fallback)**

```sql
-- Para modo PRODUCCIÓN
UPDATE companies SET 
    usuario_sol_produccion = 'tu_usuario_sol',
    clave_sol_produccion = 'tu_clave_sol_segura'
WHERE ruc = '20123456789';

-- Para modo BETA/PRUEBAS  
UPDATE companies SET 
    usuario_sol_beta = 'tu_usuario_sol_beta',
    clave_sol_beta = 'tu_clave_sol_beta'
WHERE ruc = '20123456789';
```

### **3. Ejemplo de Empresa Completamente Configurada**

```sql
UPDATE companies SET 
    -- Datos básicos
    razon_social = 'EMPRESA DE EJEMPLO S.A.C.',
    ruc = '20123456789',
    modo_produccion = false, -- Cambiar a true en producción
    activo = true,
    
    -- Credenciales OAuth2 (Principal)
    gre_client_id_beta = 'test_client_id_beta',
    gre_client_secret_beta = 'test_client_secret_beta_muy_secreto',
    gre_client_id_produccion = 'prod_client_id_production', 
    gre_client_secret_produccion = 'prod_client_secret_production_muy_secreto',
    
    -- Credenciales SOL (Fallback)
    usuario_sol_beta = 'TESTUSER',
    clave_sol_beta = 'testpassword123',
    usuario_sol_produccion = 'PRODUSER',
    clave_sol_produccion = 'prodpassword456'
    
WHERE id = 1;
```

## 📝 Variables de Postman

### **Configuración Inicial de Variables**

```json
{
    "base_url": "http://localhost:8000/api",
    "company_id": "1",
    "company_ruc": "20123456789",
    "company_name": "EMPRESA DE EJEMPLO S.A.C.",
    "invoice_id": "1",
    "boleta_id": "1",
    "access_token": ""
}
```

### **Variables que se Auto-guardan**
- ✅ `access_token` - Al hacer login
- ✅ `company_id` - Al seleccionar empresa
- ✅ `company_ruc` - Al seleccionar empresa  
- ✅ `company_name` - Al seleccionar empresa
- ✅ `user_id` - Al hacer login
- ✅ `cdr_filename` - Al descargar CDR

## 🧪 Datos de Prueba

### **Documentos de Ejemplo para Testing**

```sql
-- Insertar factura de ejemplo
INSERT INTO invoices (
    company_id, tipo_documento, serie, correlativo,
    fecha_emision, mto_imp_venta, estado_sunat,
    created_at, updated_at
) VALUES (
    1, '01', 'F001', 1, 
    '2024-01-15', 118.00, null,
    NOW(), NOW()
);

-- Insertar boleta de ejemplo
INSERT INTO boletas (
    company_id, tipo_documento, serie, correlativo,
    fecha_emision, mto_imp_venta, estado_sunat,
    created_at, updated_at
) VALUES (
    1, '03', 'B001', 1,
    '2024-01-15', 59.00, null,
    NOW(), NOW()
);
```

### **Datos para Consulta Directa (Sin BD)**

```json
{
    "company_id": 1,
    "ruc_emisor": "20123456789",
    "tipo_documento": "01",
    "serie": "F001",
    "correlativo": 1,
    "fecha_emision": "2024-01-15",
    "monto_total": 118.00,
    "incluir_cdr": false
}
```

## 🔧 Configuración del Servidor

### **Variables de Entorno (.env)**

```env
# Base de datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=api_facturacion_sunat
DB_USERNAME=root
DB_PASSWORD=

# Cache (recomendado Redis para tokens)
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Logs detallados para debugging
LOG_LEVEL=debug
LOG_CHANNEL=stack

# URLs de SUNAT (no modificar)
SUNAT_API_BETA=https://api-beta.sunat.gob.pe
SUNAT_API_PROD=https://api.sunat.gob.pe
SUNAT_SOAP_BETA=https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService
SUNAT_SOAP_PROD=https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService
```

### **Permisos de Directorios**

```bash
# Crear directorios para CDRs
mkdir -p storage/app/cdr
chmod -R 755 storage/app/cdr

# Permisos de logs
chmod -R 755 storage/logs

# Cache de Laravel
php artisan cache:clear
php artisan config:clear
```

## 🎯 Escenarios de Prueba

### **Escenario 1: OAuth2 Exitoso**
- ✅ Credenciales OAuth2 configuradas
- ❌ Credenciales SOL no configuradas
- **Resultado**: Usa OAuth2, respuesta rápida

### **Escenario 2: Fallback a SOAP SOL**
- ❌ Credenciales OAuth2 incorrectas/no configuradas
- ✅ Credenciales SOL configuradas
- **Resultado**: Falla OAuth2, usa SOAP automáticamente

### **Escenario 3: Sin Credenciales**
- ❌ Sin credenciales OAuth2
- ❌ Sin credenciales SOL
- **Resultado**: Error, no puede consultar

### **Escenario 4: Consulta con CDR**
- ✅ Cualquier método funcional
- ✅ Documento válido en SUNAT
- **Resultado**: Consulta + descarga CDR automática

## 🚨 Solución de Problemas Comunes

### **Error: "Token OAuth2 no disponible"**
```sql
-- Verificar credenciales OAuth2
SELECT 
    ruc, razon_social, modo_produccion,
    gre_client_id_produccion, gre_client_id_beta,
    CASE WHEN gre_client_secret_produccion IS NOT NULL THEN '✅ Configurado' ELSE '❌ Falta' END as secret_prod,
    CASE WHEN gre_client_secret_beta IS NOT NULL THEN '✅ Configurado' ELSE '❌ Falta' END as secret_beta
FROM companies WHERE id = 1;
```

### **Error: "Credenciales SOL no configuradas"**
```sql  
-- Verificar credenciales SOL
SELECT 
    ruc, razon_social, modo_produccion,
    usuario_sol_produccion, usuario_sol_beta,
    CASE WHEN clave_sol_produccion IS NOT NULL THEN '✅ Configurado' ELSE '❌ Falta' END as clave_prod,
    CASE WHEN clave_sol_beta IS NOT NULL THEN '✅ Configurado' ELSE '❌ Falta' END as clave_beta
FROM companies WHERE id = 1;
```

### **Error: "Documento con errores"**
```sql
-- Verificar datos mínimos del documento
SELECT 
    id, serie, correlativo, tipo_documento, 
    fecha_emision, mto_imp_venta,
    CASE 
        WHEN serie IS NULL THEN '❌ Falta serie'
        WHEN correlativo IS NULL THEN '❌ Falta correlativo'  
        WHEN tipo_documento IS NULL THEN '❌ Falta tipo'
        WHEN fecha_emision IS NULL THEN '❌ Falta fecha'
        WHEN mto_imp_venta IS NULL THEN '❌ Falta monto'
        ELSE '✅ Completo'
    END as estado_validacion
FROM invoices WHERE id = 1;
```

## 📊 Monitoreo y Logs

### **Logs Importantes**
```bash
# Ver logs de consultas CPE
tail -f storage/logs/laravel.log | grep "consulta.*cpe\|CPE"

# Ver logs de errores
tail -f storage/logs/laravel.log | grep "ERROR"

# Ver logs de tokens OAuth2  
tail -f storage/logs/laravel.log | grep "token.*CPE\|OAuth2"
```

### **Cache de Tokens**
```bash
# Ver tokens en cache (Redis)
redis-cli KEYS "*sunat_token_cpe*"

# Ver contenido de token
redis-cli GET "sunat_token_cpe_1"

# Limpiar cache de tokens
redis-cli DEL sunat_token_cpe_1
```

## ✅ Lista de Verificación

### **Antes de Usar la Colección**

- [ ] ✅ Base de datos configurada y migraciones ejecutadas
- [ ] ✅ Al menos un tipo de credenciales configurado (OAuth2 o SOL)
- [ ] ✅ Empresa activa en base de datos
- [ ] ✅ Documentos de prueba creados (opcional)
- [ ] ✅ Permisos de directorio `storage/app/cdr/` configurados
- [ ] ✅ Variables de Postman configuradas
- [ ] ✅ Servidor Laravel funcionando
- [ ] ✅ Redis/Cache funcionando (recomendado)

### **Para Producción Adicional**

- [ ] ✅ Credenciales de producción válidas
- [ ] ✅ `modo_produccion = true` en empresa
- [ ] ✅ Certificados SSL configurados
- [ ] ✅ Rate limiting configurado
- [ ] ✅ Monitoreo de logs configurado
- [ ] ✅ Backups de CDRs configurados

Con esta configuración, el sistema CPE v2 estará completamente funcional y listo para realizar consultas robustas con fallback automático.