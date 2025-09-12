# 📋 Instalación de Consulta CPE

## 1. Instalar Dependencia

```bash
composer require greenter/sunat-consulta-cpe
```

## 2. Ejecutar Migraciones

```bash
php artisan migrate
```

## 3. Configurar Credenciales API SUNAT en Companies

Agregar en el modelo Company o via seeder:
```php
'api_sunat_client_id' => 'tu_client_id',
'api_sunat_client_secret' => 'tu_client_secret',
```

## 4. Uso de las APIs

### 4.1 Consulta Individual
```http
POST /api/v1/consulta-cpe/factura/{id}
Authorization: Bearer {token}
```

### 4.2 Consulta Masiva
```http
POST /api/v1/consulta-cpe/masivo
Authorization: Bearer {token}
Content-Type: application/json

{
    "company_id": 1,
    "tipo_documento": "01",
    "fecha_desde": "2024-01-01",
    "fecha_hasta": "2024-01-31",
    "limit": 50
}
```

### 4.3 Estadísticas
```http
GET /api/v1/consulta-cpe/estadisticas?company_id=1&fecha_desde=2024-01-01
Authorization: Bearer {token}
```

## 5. Comando Artisan

### Consulta masiva por empresa
```bash
php artisan consulta-cpe:masiva --company=1 --tipo=01 --fecha-desde=2024-01-01 --limite=100
```

### Consulta todos los documentos pendientes
```bash
php artisan consulta-cpe:masiva --solo-pendientes --delay=1000
```

## 6. Campos Agregados a los Modelos

- `consulta_cpe_estado`: Estado del CPE (1=Aceptado, 2=Anulado, etc.)
- `consulta_cpe_respuesta`: JSON con toda la respuesta de SUNAT  
- `consulta_cpe_fecha`: Fecha/hora de la última consulta

## 7. Cache de Tokens

Los tokens se almacenan automáticamente en cache por 45 minutos para evitar regenerarlos constantemente.

## 8. Rate Limiting

Se incluye un delay configurable entre consultas para respetar los límites de SUNAT.