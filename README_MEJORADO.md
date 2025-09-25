# API de Facturación Electrónica SUNAT

> Sistema de facturación electrónica desarrollado para cumplir con las normativas de SUNAT Perú

[![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Acerca del Proyecto

Este proyecto nació de la necesidad de contar con una solución robusta y confiable para la emisión de comprobantes electrónicos en Perú. Después de trabajar con diversos sistemas de facturación y enfrentar las complejidades de la integración con SUNAT, decidí crear una API que simplifique este proceso para otros desarrolladores y empresas.

### ¿Por qué este proyecto?

- **Experiencia real**: Desarrollado tras años de trabajo con sistemas de facturación
- **Problemas resueltos**: Aborda las dificultades comunes al integrar con SUNAT
- **Código limpio**: Arquitectura pensada para ser mantenible y escalable
- **Documentación práctica**: Ejemplos reales basados en casos de uso frecuentes

## Funcionalidades

**Documentos soportados:**
- Facturas electrónicas
- Boletas de venta
- Notas de crédito y débito
- Guías de remisión electrónicas
- Resúmenes diarios y comunicaciones de baja

**Características técnicas:**
- Gestión multi-empresa
- Cálculo automático de impuestos peruanos
- Generación de XML firmados digitalmente
- Consulta de estados en SUNAT
- API REST documentada

## Instalación

### Requisitos
```
PHP >= 8.2
Composer
MySQL o PostgreSQL
Certificado digital SUNAT (.pfx)
```

### Configuración inicial

1. Clona el repositorio:
```bash
git clone https://github.com/yorchavez9/Api-de-facturacion-electronica-sunat-Peru.git
cd Api-de-facturacion-electronica-sunat-Peru
```

2. Instala las dependencias:
```bash
composer install
```

3. Configura el entorno:
```bash
cp .env.example .env
php artisan key:generate
```

4. Configura tu base de datos en `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tu_base_datos
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña
```

5. Ejecuta las migraciones:
```bash
php artisan migrate
```

### Configuración SUNAT

Para usar el sistema necesitas:

1. **Certificado digital**: Obtén tu certificado .pfx desde SUNAT
2. **Credenciales SOL**: Usuario y clave SOL de tu empresa
3. **Configuración del entorno**: Ajusta las rutas y credenciales en `.env`

```env
SUNAT_ENVIRONMENT=beta  # o 'produccion'
SUNAT_CERTIFICATE_PATH=storage/certificates/certificado.pfx
SUNAT_CERTIFICATE_PASSWORD=tu_password_del_certificado
```

## Uso Básico

### Crear una factura

```php
// Endpoint: POST /api/v1/invoices
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "F001",
  "fecha_emision": "2024-01-15",
  "moneda": "PEN",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "EMPRESA EJEMPLO SAC"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Producto ejemplo",
      "cantidad": 1,
      "mto_valor_unitario": 100.00,
      "tip_afe_igv": "10"
    }
  ]
}
```

### Consultar estado de un documento

```bash
GET /api/v1/cpe/consult/{ruc}/{tipo}/{serie}/{numero}
```

## Comandos Útiles

```bash
# Sincronizar con SUNAT
php artisan sunat:sync-status

# Generar resúmenes pendientes
php artisan sunat:daily-summaries

# Limpiar archivos temporales
php artisan sunat:clean-files
```

## Arquitectura

El sistema está estructurado siguiendo principios SOLID y patrones de Laravel:

```
app/
├── Http/Controllers/Api/     # Controladores de la API
├── Models/                   # Modelos de datos
├── Services/                 # Lógica de negocio
│   ├── DocumentService.php   # Gestión de documentos
│   ├── GreenterService.php   # Integración SUNAT
│   └── PdfService.php        # Generación de PDFs
└── Traits/                   # Funcionalidades reutilizables
```

### Modelos principales

- `Company`: Datos de la empresa emisora
- `Branch`: Sucursales o puntos de emisión
- `Invoice`, `Boleta`, `CreditNote`: Documentos electrónicos
- `Client`: Clientes y proveedores

## Casos de Uso

### Para farmacias
El sistema incluye configuraciones específicas para el sector farmacéutico, con soporte para medicamentos exonerados según el PNME y beneficios de la Ley Amazónica.

### Para retail
Gestión de boletas masivas con resúmenes diarios automáticos.

### Para empresas de servicios
Facturación de servicios con diferentes tipos de afectación al IGV.

## Testing

```bash
# Ejecutar todas las pruebas
php artisan test

# Pruebas específicas
php artisan test --filter=InvoiceTest
```

## Documentación Adicional

- **Ejemplos de Postman**: Colecciones completas en `ejemplos-postman/`
- **Video tutoriales**: [Playlist en YouTube](https://www.youtube.com/watch?v=HrrEdjY_7MU&list=PLfwfiNJ5Qw-ZlCfGnWjnILOI4OJfJkGp5)
- **Documentación técnica**: `VERIFICAR_MAS.md` contiene análisis detallado

## Stack Tecnológico

- **Backend**: Laravel 12, PHP 8.2+
- **Base de datos**: MySQL/PostgreSQL
- **Integración SUNAT**: Greenter 5.1
- **Autenticación**: Laravel Sanctum
- **Testing**: PestPHP
- **PDF**: DomPDF con plantillas personalizadas

## Contribuir

Las contribuciones son bienvenidas. Si encuentras un bug o tienes una mejora:

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -m 'Agrega nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Abre un Pull Request

### Reportar problemas

Si encuentras algún problema, por favor:
- Describe el error claramente
- Incluye los pasos para reproducirlo
- Menciona la versión de PHP y Laravel que usas
- Adjunta logs relevantes

## Licencia y Responsabilidad

Este proyecto es de código abierto bajo licencia MIT. Puedes usar, modificar y distribuir el código libremente.

**⚠️ Importante:**
- El uso del sistema es bajo tu propia responsabilidad
- Debes contar con certificados digitales válidos de SUNAT
- Realiza pruebas exhaustivas antes de usar en producción
- Mantén actualizadas las dependencias de seguridad

## Soporte

Para consultas técnicas o reportar problemas:

- **GitHub Issues**: Para bugs y mejoras
- **WhatsApp**: [Contacto directo](https://wa.link/z50dwk)
- **Email**: [Consultas comerciales]

### Donaciones

Si este proyecto te ha ahorrado tiempo y quieres apoyar su desarrollo:

**Yape (Perú):** `920468502`

Tu apoyo ayuda a mantener el proyecto actualizado y agregar nuevas funcionalidades.

---

## Roadmap

### Próximas funcionalidades
- [ ] Integración con más bancos para pagos
- [ ] Dashboard web para gestión visual
- [ ] Webhook para notificaciones automáticas
- [ ] Soporte para otros tipos de documentos

### Versiones recientes
- **v1.2**: Soporte para Ley Amazónica y farmacias
- **v1.1**: Mejoras en consulta CPE
- **v1.0**: Release inicial

---

Desarrollado con ❤️ para la comunidad peruana de desarrolladores.

*Este proyecto surge de la experiencia práctica implementando sistemas de facturación electrónica y busca simplificar la integración con SUNAT para otros desarrolladores.*