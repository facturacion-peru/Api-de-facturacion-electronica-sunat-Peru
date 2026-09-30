# Restaurar desde una copia

Procedimiento para recuperar un ambiente (spec 009, HU-2, A-45): tanto la base de datos de un servidor que sigue en pie como un **servidor nuevo desde cero**. Objetivo: servicio de vuelta en menos de 4 horas y, como mucho, 24 horas de datos perdidos (una copia diaria).

## Qué hace falta antes de empezar

Todo esto vive **fuera del servidor**, en el gestor de contraseñas (ver `PREPARACION.md`):

| Dato | Para qué | Si se pierde |
|---|---|---|
| **`APP_KEY` del ambiente** | Descifra la clave SOL y la contraseña del certificado de cada empresa, guardadas cifradas en la base | Las empresas tendrán que volver a cargar su configuración SUNAT. El resto de los datos se recupera |
| **`BACKUP_ARCHIVE_PASSWORD`** | Descifra el `.zip` de la copia (AES-256) | La copia es **irrecuperable** |
| Claves S3 (`AWS_*`) | Descargar la copia | Se regeneran en el proveedor |
| Resto del `.env` (correo, monitores) | Funcionamiento normal | Se regeneran en cada proveedor |

La copia contiene la base de datos completa (`db-dumps/`) y los logos de las empresas (`storage/app/public`). Los certificados digitales y la clave SOL van dentro de la base, cifrados con la `APP_KEY`.

## A. Servidor nuevo desde cero

1. **Recupera la `APP_KEY`** y la `BACKUP_ARCHIVE_PASSWORD` del gestor de contraseñas. Sin la primera, sigue igual, pero avisa a las empresas de que deberán volver a cargar su configuración SUNAT.
2. **Prepara el servidor:** crea un VPS con Ubuntu 24.04, apunta el DNS de los subdominios a su nueva IP y ejecuta `deploy/provision.sh` como root (ver `PREPARACION.md`). Deja creados la base, el usuario `deploy` y `shared/.env` a partir de la plantilla.
3. **Completa `shared/.env`** en `/srv/sunat/<ambiente>/api/shared/.env` con los valores del gestor de contraseñas. Pon **la misma `APP_KEY` de antes**, nunca una nueva. La contraseña de la base es la que generó `provision.sh` en `/root/sunat-db-<ambiente>.txt`.
4. **Despliega el código:** lanza el workflow de despliegue del ambiente en GitHub Actions, o sube el paquete y ejecuta `deploy/release.sh <ambiente> api <paquete.tar.gz>` y lo mismo para `web`. Las migraciones crean las tablas vacías, que la restauración reemplaza.
5. **Descarga la copia más reciente** del almacenamiento S3 (carpeta `sunat-<ambiente>/`), por ejemplo desde la consola del proveedor o con `aws s3 cp --endpoint-url "$AWS_ENDPOINT" s3://<bucket>/sunat-<ambiente>/<fecha>.zip .`.
6. **Restaura** como usuario `deploy`:

   ```bash
   BACKUP_ARCHIVE_PASSWORD='<del gestor>' /srv/sunat/<ambiente>/api/current/deploy/restore.sh <fecha>.zip <ambiente>
   ```

   El script pide escribir el nombre de la base para confirmar. Después pone la aplicación en mantenimiento, descifra la copia, reemplaza el esquema `public` de la base, copia los logos y vuelve a levantar la aplicación.
7. **Comprueba** (ver «Después de restaurar»).

## B. Solo la base de datos, con el servidor en pie

Para un borrado accidental o una base dañada: sigue los pasos 5 a 7. La `APP_KEY` del servidor ya es la correcta.

Todo lo registrado después de la copia se pierde, incluidos los comprobantes emitidos a SUNAT ese día. Anótalos antes si es posible (ver abajo).

## Después de restaurar

- [ ] `https://api.<dominio>/up` responde 200 y se puede iniciar sesión.
- [ ] En una empresa, **Configuración SUNAT** aparece como validada. Si da error de descifrado, la `APP_KEY` no es la de la copia.
- [ ] `php artisan ops:check` no avisa nada raro y `sunat:send-pending` vuelve a latir en el monitor.
- [ ] **Comprobantes emitidos después de la copia:** SUNAT los tiene y nuestra base no. La numeración seguiría desde el último correlativo restaurado y chocaría con números ya aceptados. Antes de reabrir la emisión, consulta los últimos correlativos aceptados de cada serie en SUNAT y avisa a las empresas afectadas. Es un paso manual.
- [ ] Deja constancia en la bitácora: fecha, copia usada y tiempo total.

## Prueba mensual

`php artisan ops:restore-test`, programado el día 1 de cada mes a las 05:00 (o `deploy/restore-test.sh`), repite los pasos 5 y 6 sobre una base temporal (`<base>_restore_test`):

1. descarga la copia más reciente, la descifra y la carga en esa base;
2. comprueba los conteos y que un secreto SUNAT se descifra con la `APP_KEY` actual;
3. borra la base temporal y avisa el resultado por correo.

No toca la base real. Si falla, la copia o la `APP_KEY` guardada no sirven, y hay que resolverlo ese mismo día.

## Restauración de prueba en local

Para comprobar `restore.sh` sin servidor, contra una base aparte:

```bash
export BACKUP_ARCHIVE_PASSWORD=prueba-local
BACKUP_DISK=local php artisan backup:run --only-db --disable-notifications
createdb db_api_sunat_restore
ENV_FILE=.env RESTORE_DB=db_api_sunat_restore SKIP_MAINTENANCE=1 STORAGE_DIR=/tmp/logos \
  deploy/restore.sh storage/app/private/sunat-local/<fecha>.zip staging --yes
# Compara conteos, luego: dropdb db_api_sunat_restore y borra el .zip
```
