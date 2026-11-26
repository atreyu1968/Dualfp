# Auditoría funcional — 26/11/2026

## Resultados

La revisión compara las huellas Git de los archivos PHP/JS/CSS principales con la copia local del repositorio. Los archivos corregidos index.php, auth.php, install.sh, ubuntu/install.sh y update.sh se verificaron de nuevo por huella y sintaxis.

- 87 comprobaciones HTTP superadas con PHP 8.4 y datos ficticios.
- 21 comprobaciones de regresión de campaigns_test.php e imports_test.php superadas.
- 4 comprobaciones DOM/JavaScript en Chromium superadas (calculadora, menú móvil, enlace visible y ausencia de excepciones). El navegador no pudo acceder directamente al servidor de prueba por una restricción del entorno; se utilizó el HTML servido, con JS y CSS locales. Las operaciones HTTP se realizaron separadamente.
- 0 errores PHP en las operaciones HTTP ensayadas.
- 2 escenarios del actualizador simulados en un árbol temporal, con Git y systemd sustituidos por dobles de prueba: actualización correcta y fallo de arranque con restauración del código, conservando datos y configuración.
- Sintaxis de PHP, Bash y JavaScript revisada. Directorio privado de 71 empresas validado sin publicarlo.

## Cambios correctivos

- Invitaciones caducadas dejan de reservar el correo; se bloquean colisiones entre invitaciones y registros.
- Un tutor no puede compartir documentos alterando manualmente una petición POST. Solo el administrador puede habilitar visibilidad de documentos del centro.
- El ZIP de empresa solo incluye datos de su colaboración y el grupo vinculado; no incluye alumnado ni otras empresas.
- Validación de fechas reales y direcciones de correo de tutores/sedes; registro condicionado a configuración de correo.
- Recuperación de instalaciones incompletas; bloqueo compartido entre instalación y actualización; comprobación de extensiones PHP; restauración del código ante fallo de reactivación de servicios.

## Pruebas que se pueden repetir

En un entorno de desarrollo aislado, desde la raíz del código:

```bash
php tests/campaigns_test.php
php tests/imports_test.php
for f in *.php ubuntu/*.php tests/*.php; do php -l "$f" || exit 1; done
for f in install.sh update.sh ubuntu/install.sh; do bash -n "$f" || exit 1; done
```

## Limitaciones y aceptación en producción

No se ha instalado en el Ubuntu definitivo, ni se ha probado el systemd real, TLS/DNS, ni un envío real de Resend. El entorno de revisión ejecuta Debian/PHP 8.4 y no tiene acceso directo a GitHub ni credenciales de Resend. La recuperación de código no restaura automáticamente datos y no reemplaza un backup probado. No se ha realizado una auditoría de seguridad independiente ni una prueba de carga.

Persiste la limitación conocida de una colaboración por empresa y curso, con un único grupo; todavía no permite vincular la misma empresa a varios grupos del mismo curso. La importación directa XLSX, lectura automática de respuestas, generación de anexos oficiales y firma electrónica no están implementadas. Los datos empresariales iniciales y la cuantía de ayudas son configurables y requieren comprobación antes de publicar o enviar.

Antes de habilitar datos reales: instalación en Ubuntu 24.04, HTTPS, verificación de servicios, prueba de Resend con un destinatario autorizado, prueba de backup/restauración y revisión de privacidad del centro. Mantener campañas desactivadas durante esta comprobación.

## Revisión 3.7 — Colaboraciones múltiples

Se ha eliminado la limitación de un grupo por empresa y curso mediante referencias estables, sin cambiar las colaboraciones históricas. 124 comprobaciones HTTP (cero errores PHP), 21 de campañas/importación y 17 pruebas de multigrupo superadas localmente con datos ficticios y correo simulado. Se verificaron las huellas Git de los archivos PHP modificados frente a la rama de desarrollo. Se mantiene la aceptación pendiente en Ubuntu y Resend real. Capacidad global de empresa y solapamientos horarios entre grupos requieren revisión humana. La limitación multigrupo descrita en la auditoría anterior queda superada.

## Revisión 3.8 — Buzón empresarial

Pruebas locales: 124 HTTP funcionales anteriores, 38 regresiones de campañas/importación/multigrupo, 24 pruebas del buzón y 25 HTTP del webhook, en total 211 comprobaciones. Sin errores PHP en los escenarios HTTP. Se ha comprobado firma HMAC, caducidad, destinatario, deduplicación, acceso privado, retención de seguimiento, asociación manual, respuesta simulada y borrado. Se requiere prueba de recepción y entrega real en Resend, configuración de dominio y despliegue Ubuntu. No se descargan adjuntos ni se envían respuestas automáticas.

## Revisión 3.9 — Prospección y autorización

162 comprobaciones HTTP funcionales; 38 regresiones de campañas, importación y multigrupo; 24 pruebas del buzón; 25 HTTP del webhook; 6 SMTP/MIME: 255 en total. Pruebas con datos ficticios y servidor SMTP local simulado, sin envíos externos. La manifestación de interés requiere verificación, la autorización de campañas es opcional, las bajas se conservan y ninguna solicitud confirma plazas. Se requiere comprobación con el proveedor SMTP real (LOGIN con TLS; OAuth2 no implementado), Resend y servidor de destino. Las credenciales se almacenan en directorio privado, sin cifrado adicional. Favicon SVG incorporado a instalación y actualización.
