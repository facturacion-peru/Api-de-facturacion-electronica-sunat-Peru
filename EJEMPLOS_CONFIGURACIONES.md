# 📋 Ejemplos de Configuraciones de Empresa

## 🔧 Configuraciones Disponibles

### 1. **tax_settings** - Configuración de Impuestos
### 2. **invoice_settings** - Configuración de Facturación  
### 3. **gre_settings** - Configuración de Guías de Remisión
### 4. **document_settings** - Configuración de Documentos

---

## 🌐 Variables de Entorno

```bash
# Para desarrollo local
BASE_URL=http://localhost:8000/api/v1
COMPANY_ID=1
TOKEN=your_bearer_token_here
```

---

## 📊 1. TAX_SETTINGS - Configuración de Impuestos

### 🔍 Obtener Configuración Actual
```bash
curl -X GET \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/tax_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### ✏️ Actualizar Configuración
```bash
curl -X PUT \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/tax_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "igv_porcentaje": 18,
    "icbper_monto": 0.5,
    "isc_porcentaje": 0,
    "ivap_porcentaje": 4,
    "decimales_cantidad": 10,
    "decimales_precio_unitario": 10,
    "redondeo_automatico": true,
    "validar_ruc_cliente": true,
    "permitir_precio_cero": false,
    "incluir_leyenda_monto": true
  }'
```

### 📝 Valores Permitidos:
- **igv_porcentaje**: 0-50 (decimal)
- **icbper_monto**: ≥0 (decimal) 
- **decimales_precio_unitario**: 2-10 (entero)
- **decimales_cantidad**: 2-10 (entero)
- **redondeo_automatico**: true/false
- **validar_ruc_cliente**: true/false

---

## 🧾 2. INVOICE_SETTINGS - Configuración de Facturación

### 🔍 Obtener Configuración
```bash
curl -X GET \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/invoice_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### ✏️ Actualizar Configuración
```bash
curl -X PUT \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/invoice_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "ubl_version": "2.1",
    "formato_numero": "F###-########",
    "moneda_default": "PEN",
    "tipo_operacion_default": "0101",
    "incluir_leyendas_automaticas": true,
    "envio_automatico": false
  }'
```

### 📝 Valores Permitidos:
- **ubl_version**: "2.0", "2.1"
- **moneda_default**: "PEN", "USD", "EUR"
- **formato_numero**: string (máx 50 caracteres)
- **tipo_operacion_default**: código de operación SUNAT
- **incluir_leyendas_automaticas**: true/false
- **envio_automatico**: true/false

---

## 🚛 3. GRE_SETTINGS - Configuración de Guías de Remisión

### 🔍 Obtener Configuración
```bash
curl -X GET \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/gre_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### ✏️ Actualizar Configuración
```bash
curl -X PUT \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/gre_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "peso_default_kg": 1.0,
    "bultos_default": 1,
    "modalidad_transporte_default": "01",
    "motivo_traslado_default": "01",
    "verificacion_automatica": true
  }'
```

### 📝 Valores Permitidos:
- **peso_default_kg**: ≥0.001 (decimal)
- **bultos_default**: ≥1 (entero)
- **modalidad_transporte_default**: "01" (público), "02" (privado)
- **motivo_traslado_default**: "01" a "19" (códigos SUNAT)
- **verificacion_automatica**: true/false

---

## 📄 4. DOCUMENT_SETTINGS - Configuración de Documentos

### 🔍 Obtener Configuración
```bash
curl -X GET \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/document_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### ✏️ Actualizar Configuración
```bash
curl -X PUT \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/document_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "generar_xml_automatico": true,
    "generar_pdf_automatico": true,
    "enviar_sunat_automatico": false,
    "formato_pdf_default": "a4",
    "orientacion_pdf_default": "portrait",
    "incluir_qr_pdf": true,
    "incluir_hash_pdf": true,
    "logo_en_pdf": true
  }'
```

### 📝 Valores Permitidos:
- **formato_pdf_default**: "a4", "letter", "legal"
- **orientacion_pdf_default**: "portrait", "landscape"
- **generar_xml_automatico**: true/false
- **generar_pdf_automatico**: true/false
- **enviar_sunat_automatico**: true/false
- **incluir_qr_pdf**: true/false

---

## 🔄 5. Operaciones Adicionales

### 📋 Obtener Todas las Configuraciones
```bash
curl -X GET \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### 🔄 Resetear Configuración a Valores por Defecto
```bash
curl -X POST \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/reset" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "section": "tax_settings"
  }'
```

### 🧹 Limpiar Cache de Configuración
```bash
curl -X DELETE \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/cache" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

### ✅ Validar Configuraciones SUNAT
```bash
curl -X POST \
  "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/validate" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Accept: application/json"
```

---

## 🎯 Ejemplos de Respuestas

### ✅ Respuesta Exitosa
```json
{
  "success": true,
  "data": {
    "section": "tax_settings",
    "config": {
      "igv_porcentaje": 18,
      "icbper_monto": 0.5,
      "decimales_precio_unitario": 10,
      "redondeo_automatico": true
    }
  },
  "message": "Configuración de tax_settings actualizada correctamente"
}
```

### ❌ Respuesta de Error
```json
{
  "success": false,
  "message": "Error al actualizar configuración: igv_porcentaje debe ser entre 0 y 50",
  "errors": {
    "igv_porcentaje": ["The igv porcentaje must be between 0 and 50."]
  }
}
```

---

## 🚀 Casos de Uso Comunes

### 1. **Configurar empresa para producción**
```bash
# 1. Configurar impuestos estándar Peru
curl -X PUT "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/tax_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -d '{"igv_porcentaje": 18, "icbper_monto": 0.5, "decimales_precio_unitario": 2}'

# 2. Configurar generación automática de documentos
curl -X PUT "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/document_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -d '{"generar_pdf_automatico": true, "enviar_sunat_automatico": true}'
```

### 2. **Configurar para testing/desarrollo**
```bash
# Configurar sin envío automático a SUNAT
curl -X PUT "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/document_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -d '{"enviar_sunat_automatico": false, "generar_pdf_automatico": true}'
```

### 3. **Configurar para empresa de transporte**
```bash
# Configurar valores por defecto para guías de remisión
curl -X PUT "{{BASE_URL}}/companies/{{COMPANY_ID}}/config/gre_settings" \
  -H "Authorization: Bearer {{TOKEN}}" \
  -H "Content-Type: application/json" \
  -d '{"modalidad_transporte_default": "02", "peso_default_kg": 25.0, "bultos_default": 5}'
```

---

## 🔐 Notas de Seguridad

- ✅ Todos los endpoints requieren autenticación Bearer Token
- ✅ Solo se permiten las 4 configuraciones esenciales
- ✅ Validación estricta de tipos de datos
- ✅ Los service_endpoints están predefinidos en el sistema
- ✅ Cache automático de 30 minutos para optimizar performance