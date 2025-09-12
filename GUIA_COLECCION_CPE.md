# 📚 Guía de Uso - Colección CPE Consultas v2

## 🎯 Descripción General

La **API SUNAT - Consultas CPE v2 (Mejorado)** es una colección de Postman especializada para realizar consultas de Comprobantes de Pago Electrónicos (CPE) utilizando el sistema mejorado que combina:

- **🚀 API OAuth2 de SUNAT** (método principal)
- **🔧 SOAP tradicional con credenciales SOL** (método fallback)
- **📦 Descarga automática de CDRs**
- **✅ Validación robusta de documentos**
- **🎯 Consultas por datos directos**

## 📋 Estructura de la Colección

### 🔐 **1. AUTENTICACIÓN**
- **1.1** - Login y Obtener Token
- **1.2** - Verificar Token Actual

### 🏢 **2. SELECCIÓN DE EMPRESA**  
- **2.1** - Listar Empresas Disponibles
- **2.2** - Ver Detalles de Empresa Seleccionada

### 📄 **3. CONSULTAS INDIVIDUALES CPE**
- **3.1** - Consultar Factura (Método Mejorado)
- **3.2** - Consultar Factura con Descarga de CDR
- **3.3** - Consultar Boleta (Método Mejorado)

### 🎯 **4. CONSULTA POR DATOS DIRECTOS**
- **4.1** - Consultar por Datos (Sin CDR)
- **4.2** - Consultar por Datos (Con CDR)
- **4.3** - Consultar Nota de Crédito por Datos

### 🚀 **5. CONSULTAS MASIVAS**
- **5.1** - Consulta Masiva (Todos los Pendientes)
- **5.2** - Consulta Masiva (Solo Facturas)
- **5.3** - Consulta Masiva (Por Rango de Fechas)

### ✅ **6. VALIDACIÓN DE DOCUMENTOS**
- **6.1** - Validar Factura
- **6.2** - Validar Boleta

### 📦 **7. GESTIÓN DE CDRs**
- **7.1** - Listar CDRs Guardados
- **7.2** - Descargar CDR Específico

### 🧪 **8. PRUEBAS Y DEBUGGING**
- **8.1** - Test de Conectividad API
- **8.2** - Test de Datos de Ejemplo

## 🚀 Guía de Inicio Rápido

### **Paso 1: Configurar Variables**
```json
{
  "base_url": "http://localhost:8000/api",
  "company_id": "1",
  "invoice_id": "1",
  "boleta_id": "1"
}
```

### **Paso 2: Ejecutar Autenticación**
1. Ejecutar **1.1 - Login y Obtener Token**
2. El token se guarda automáticamente en `{{access_token}}`
3. Verificar con **1.2 - Verificar Token Actual**

### **Paso 3: Seleccionar Empresa**
1. Ejecutar **2.1 - Listar Empresas Disponibles**
2. Los datos de la empresa se guardan automáticamente
3. Verificar con **2.2 - Ver Detalles de Empresa**

### **Paso 4: Realizar Consultas**
Ahora puedes usar cualquier endpoint de consulta CPE.

## 💡 Ejemplos de Uso

### **Consulta Individual con Fallback Automático**

**Endpoint**: `3.1 - Consultar Factura (Método Mejorado)`

**Funcionamiento**:
1. Intenta consultar con OAuth2
2. Si falla, usa automáticamente SOAP SOL
3. Muestra qué método fue utilizado

**Respuesta exitosa**:
```json
{
  "success": true,
  "message": "Consulta realizada correctamente",
  "data": {
    "comprobante": {
      "serie": "F001",
      "correlativo": 123
    },
    "consulta": {
      "codigo": "0",
      "mensaje": "Aceptado"
    },
    "metodo_usado": "api_oauth2"
  }
}
```

### **Consulta por Datos Directos**

**Endpoint**: `4.1 - Consultar por Datos (Sin CDR)`

**Parámetros**:
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

**Ventaja**: No necesitas tener el documento guardado en tu base de datos.

### **Consulta Masiva Inteligente**

**Endpoint**: `5.3 - Consulta Masiva (Por Rango de Fechas)`

**Funcionalidades**:
- Filtra por tipo de documento
- Rango de fechas personalizable
- Límite configurable (máx. 100)
- Control automático de rate limiting
- Estadísticas detalladas

## 🔧 Variables Automáticas

La colección maneja automáticamente estas variables:

| Variable | Descripción | Auto-guardado |
|----------|-------------|---------------|
| `access_token` | Token de autenticación | ✅ Al hacer login |
| `company_id` | ID de empresa seleccionada | ✅ Al listar empresas |
| `company_ruc` | RUC de la empresa | ✅ Al listar empresas |
| `company_name` | Razón social | ✅ Al listar empresas |
| `cdr_filename` | Nombre del último CDR | ✅ Al descargar CDR |
| `user_id` | ID del usuario | ✅ Al hacer login |

## 📊 Scripts de Test Automáticos

Cada endpoint incluye scripts que:

### **Scripts Pre-Request**
- Registran el nombre del request en consola
- Validan que las variables requeridas estén configuradas

### **Scripts Post-Response**
- Guardan automáticamente tokens y datos importantes
- Muestran información relevante en la consola
- Calculan tiempos de respuesta
- Validan estructura de respuestas

### **Ejemplo de Output en Consola**
```
🚀 Ejecutando request: Consultar Factura (Método Mejorado)
✅ Consulta exitosa
📄 Comprobante: F001-123
🔧 Método usado: soap_sol
📊 Estado: 0
💬 Mensaje: Aceptado
⏱️ Tiempo respuesta: 1250ms - Status: 200
```

## 🛠️ Configuración de Credenciales

### **Para OAuth2 (Método Principal)**
Asegúrate de que la empresa tenga configurado:
```sql
UPDATE companies SET 
  gre_client_id_produccion = 'tu_client_id',
  gre_client_secret_produccion = 'tu_client_secret',
  modo_produccion = true
WHERE id = {company_id};
```

### **Para SOAP SOL (Método Fallback)**
```sql
UPDATE companies SET 
  usuario_sol_produccion = 'tu_usuario_sol',
  clave_sol_produccion = 'tu_clave_sol'
WHERE id = {company_id};
```

## 🎯 Casos de Uso Recomendados

### **1. Consulta de Documentos Existentes**
- Usar endpoints **3.x** (Consultas Individuales)
- Ideal para verificar documentos ya emitidos

### **2. Consulta de Documentos Externos**
- Usar endpoints **4.x** (Consulta por Datos Directos)
- Ideal para verificar documentos de otros sistemas

### **3. Sincronización Masiva**
- Usar endpoints **5.x** (Consultas Masivas)
- Ideal para actualizar estados pendientes

### **4. Obtención de CDRs**
- Usar **3.2** (Consulta con CDR) o **4.2** (Por datos con CDR)
- Los CDRs se guardan automáticamente en el servidor

### **5. Validación Previa**
- Usar endpoints **6.x** (Validación)
- Ideal para verificar si un documento tiene datos completos

## 🚨 Consideraciones Importantes

### **Rate Limiting**
- Las consultas masivas incluyen delay automático de 0.5 segundos
- No excedas 100 documentos por consulta masiva

### **Métodos de Consulta**
- **OAuth2**: Más rápido, requiere credenciales GRE
- **SOAP SOL**: Más compatible, requiere usuario/clave SOL
- El sistema elige automáticamente el mejor método disponible

### **Almacenamiento de CDRs**
- Los CDRs se guardan en `storage/app/cdr/{ruc}/`
- Formato: `R-{ruc}-{tipo}-{serie}-{correlativo}.zip`
- Acceso vía endpoints **7.x**

### **Logs y Debugging**
- Todos los errores se registran en logs de Laravel
- Usar endpoints **8.x** para pruebas de conectividad
- La consola de Postman muestra información detallada

## 📈 Monitoreo y Estadísticas

La colección proporciona información en tiempo real sobre:

- ✅ **Tasa de éxito** de consultas
- 🔧 **Métodos utilizados** (OAuth2 vs SOAP)
- ⏱️ **Tiempos de respuesta**
- 📦 **CDRs descargados**
- 🎯 **Documentos consultados**

## 🤝 Integración con Workflow

### **Workflow Sugerido**:
1. **Autenticación** → Login inicial
2. **Selección** → Elegir empresa
3. **Validación** → Verificar documentos (opcional)
4. **Consulta** → Individual, masiva o por datos
5. **CDR** → Descargar si es necesario
6. **Monitoreo** → Revisar resultados y logs

Esta colección está diseñada para ser intuitiva y proporcionar toda la funcionalidad necesaria para gestionar consultas CPE de manera eficiente y robusta.