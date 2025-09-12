# 🚀 Sistema de Consulta CPE Mejorado

## 📋 Resumen de Mejoras Implementadas

Este documento describe las mejoras implementadas al sistema de consulta de Comprobantes de Pago Electrónicos (CPE) del proyecto, combinando las mejores prácticas del demo de Greenter con la implementación existente.

## 🔄 Métodos de Consulta

### 1. **API OAuth2 (Método Principal)**
- **Servicio**: API moderna de SUNAT con autenticación OAuth2
- **Ventajas**: Rápido, moderno, estable
- **Cache**: Sistema inteligente de tokens con 45 minutos de duración
- **Limitaciones**: Requiere credenciales GRE configuradas

### 2. **SOAP SOL (Método Fallback)**
- **Servicio**: Servicio tradicional SOAP de SUNAT con credenciales SOL
- **Ventajas**: Compatible con credenciales tradicionales, descarga de CDR
- **Uso**: Se activa automáticamente cuando OAuth2 no está disponible
- **Basado en**: Demo oficial de Greenter

## 📁 Archivos Creados/Modificados

### Nuevos Archivos:

1. **`app/Services/ConsultaCpeServiceMejorado.php`**
   - Servicio principal con sistema dual de consulta
   - Manejo automático de fallback entre métodos
   - Validación robusta de datos
   - Gestión de CDRs comprimidos

2. **`app/Http/Controllers/Api/ConsultaCpeControllerMejorado.php`**
   - Controlador con endpoints avanzados
   - Consulta por datos directos (sin documento en BD)
   - Descarga y listado de CDRs
   - Validación de documentos

3. **`routes/api_consulta_mejorada.php`**
   - Rutas para los nuevos endpoints
   - Prefijo: `/api/v1/consulta-cpe-v2/`

4. **`CONSULTA_CPE_MEJORADA.md`** (este archivo)
   - Documentación completa del sistema mejorado

### Archivos Base Mantenidos:
- `app/Services/ConsultaCpeService.php` (original)
- `app/Http/Controllers/Api/ConsultaCpeController.php` (original)
- Rutas originales en `routes/api.php`

## 🛠️ Funcionalidades Implementadas

### 🔍 **Consultas Individuales**
- **Facturas mejoradas**: `POST /api/v1/consulta-cpe-v2/factura/{id}`
- **Facturas con CDR**: `POST /api/v1/consulta-cpe-v2/factura/{id}/con-cdr`
- **Boletas mejoradas**: `POST /api/v1/consulta-cpe-v2/boleta/{id}`

### 📊 **Consulta por Datos Directos**
- **Endpoint**: `POST /api/v1/consulta-cpe-v2/por-datos`
- **Uso**: Consultar sin tener el documento guardado en la base de datos
- **Parámetros**:
  ```json
  {
    "company_id": 1,
    "ruc_emisor": "20123456789",
    "tipo_documento": "01",
    "serie": "F001",
    "correlativo": 123,
    "fecha_emision": "2024-01-15",
    "monto_total": 100.00,
    "incluir_cdr": true
  }
  ```

### 🚀 **Consulta Masiva Mejorada**
- **Endpoint**: `POST /api/v1/consulta-cpe-v2/masivo`
- **Filtros**: Por tipo de documento, rango de fechas
- **Límite**: Máximo 100 documentos por consulta
- **Control**: Delay automático entre consultas (0.5 segundos)

### ✅ **Validación de Documentos**
- **Endpoint**: `GET /api/v1/consulta-cpe-v2/validar/{tipo}/{id}`
- **Función**: Verificar si un documento tiene todos los datos requeridos
- **Tipos soportados**: `factura`, `boleta`, `nota_credito`, `nota_debito`

### 📦 **Gestión de CDRs**
- **Listar CDRs**: `GET /api/v1/consulta-cpe-v2/cdr/{companyId}`
- **Descargar CDR**: `GET /api/v1/consulta-cpe-v2/cdr/{companyId}/{filename}`
- **Almacenamiento**: `storage/app/cdr/{ruc}/`
- **Formato**: `R-{ruc}-{tipo}-{serie}-{correlativo}.zip`

## 💻 Ejemplos de Uso

### 1. Consultar Factura con Fallback Automático
```bash
curl -X POST "http://localhost:8000/api/v1/consulta-cpe-v2/factura/123" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Respuesta**:
```json
{
  "success": true,
  "message": "Consulta realizada correctamente",
  "data": {
    "comprobante": {
      "id": 123,
      "tipo_documento": "01",
      "serie": "F001",
      "correlativo": 123
    },
    "consulta": {
      "codigo": "0",
      "mensaje": "Aceptado",
      "metodo": "api_oauth2"
    },
    "metodo_usado": "api_oauth2"
  }
}
```

### 2. Consultar por Datos Directos con CDR
```bash
curl -X POST "http://localhost:8000/api/v1/consulta-cpe-v2/por-datos" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": 1,
    "ruc_emisor": "20123456789",
    "tipo_documento": "01",
    "serie": "F001",
    "correlativo": 123,
    "fecha_emision": "2024-01-15",
    "monto_total": 100.00,
    "incluir_cdr": true
  }'
```

### 3. Consulta Masiva Filtrada
```bash
curl -X POST "http://localhost:8000/api/v1/consulta-cpe-v2/masivo" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": 1,
    "tipo_documento": ["01", "03"],
    "fecha_desde": "2024-01-01",
    "fecha_hasta": "2024-01-31",
    "limite": 50
  }'
```

## 🔧 Configuración Requerida

### Para Método OAuth2 (Principal):
```sql
-- En tabla companies:
UPDATE companies SET 
  gre_client_id_produccion = 'tu_client_id_prod',
  gre_client_secret_produccion = 'tu_client_secret_prod',
  gre_client_id_beta = 'tu_client_id_beta',
  gre_client_secret_beta = 'tu_client_secret_beta'
WHERE id = company_id;
```

### Para Método SOAP (Fallback):
```sql
-- En tabla companies:
UPDATE companies SET 
  usuario_sol_produccion = 'tu_usuario_sol_prod',
  clave_sol_produccion = 'tu_clave_sol_prod',
  usuario_sol_beta = 'tu_usuario_sol_beta',
  clave_sol_beta = 'tu_clave_sol_beta'
WHERE id = company_id;
```

## 🎯 Ventajas del Sistema Mejorado

### ✅ **Robustez**
- Sistema de fallback automático
- Validación completa de datos
- Manejo robusto de errores
- Logs detallados para debugging

### ⚡ **Rendimiento**
- Cache inteligente de tokens OAuth2
- Control de rate limiting
- Optimización de consultas masivas

### 🔄 **Compatibilidad**
- Mantiene endpoints originales
- Soporte para ambos métodos de SUNAT
- Compatible con credenciales existentes

### 📊 **Funcionalidad Avanzada**
- Consulta por datos sin BD
- Descarga automática de CDRs
- Validación previa de documentos
- Estadísticas de consultas

## 🚨 Consideraciones Importantes

1. **Credenciales**: El sistema requiere al menos uno de los dos tipos de credenciales
2. **Fallback**: Si OAuth2 falla, se usa automáticamente SOAP SOL
3. **Rate Limiting**: Delay de 0.5 segundos entre consultas masivas
4. **Storage**: Los CDRs se guardan en `storage/app/cdr/{ruc}/`
5. **Logs**: Todos los errores se registran en los logs de Laravel

## 📝 Próximas Mejoras Sugeridas

- [ ] Interface web para consultas
- [ ] Cron job para consultas automáticas
- [ ] Notificaciones por email de cambios de estado
- [ ] Exportación de reportes de consultas
- [ ] API rate limiting más sofisticado

## 🤝 Integración con el Proyecto Existente

El sistema mejorado coexiste pacíficamente con el sistema original:

- **Endpoints originales**: Siguen funcionando normalmente
- **Base de datos**: No se modificó el esquema existente
- **Configuraciones**: Usa las mismas tablas de configuración
- **Servicios**: El servicio original permanece intacto

Para usar el sistema mejorado, simplemente cambia los endpoints de `/consulta-cpe/` a `/consulta-cpe-v2/`.