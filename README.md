# FP Dual Conecta A&G 3.6 · Ubuntu y Resend

## Instalación desde GitHub (recomendada)

```bash
sudo apt update
sudo apt install -y curl ca-certificates
curl -fsSL https://raw.githubusercontent.com/atreyu1968/Dualfp/main/install.sh -o install-fpdual.sh
# Revisar el instalador antes de ejecutarlo.
sudo bash install-fpdual.sh
```

El instalador descarga la rama `main` del repositorio, comprueba que contiene todos los módulos y llama al instalador Ubuntu. Solicita el administrador inicial, dominio y configuración de HTTPS. Resend y los restantes parámetros funcionales se configuran desde el panel.

Para cargar el directorio de prospección inicial en una instalación nueva, copie al servidor el JSON privado entregado por separado y ejecute:

```bash
sudo bash install-fpdual.sh --seed /ruta/fpdual_directorio_privado.json
```

La carga se valida y se almacena en `/var/lib/fpdual/seed.json` con permisos 0600, fuera del directorio público. El repositorio público contiene un `seed.json` vacío y dos empresas ficticias **exclusivamente en la prueba automatizada**, no en el directorio de producción. Si ya existen datos, no se sustituyen. Sin carga privada, se parte de cero empresas y de las 28 fichas vacías del grupo inicial. El tutor responsable se configura desde el panel. No incorpore el JSON privado al repositorio.

Para actualizar, repita el mismo instalador durante una ventana de mantenimiento. Antes de actualizar, se crea una copia privada de configuración y datos en `/var/backups/fpdual/`. Supervise espacio libre y custodie las copias.

Alternativamente, clone el repositorio y ejecute `sudo bash ubuntu/install.sh` (acepta también `--seed`).


## Alcance funcional

Aplicación exclusiva de la Familia Profesional de Administración y Gestión. Los grupos nuevos se configuran con un catálogo cerrado (CFGB Servicios Administrativos, CFGM Gestión Administrativa, CFGS Administración y Finanzas y CFGS Asistencia a la Dirección), nivel y etiqueta opcional. Solo se publica la oferta realmente creada para el curso activo. El grupo inicial es primero de Gestión Administrativa. Los sectores de las empresas no se restringen: cualquier entidad con tareas administrativas idóneas puede colaborar. Conecta FP Canarias es una iniciativa multifamilia independiente, enlazable desde Configuración; no se comparten registros ni credenciales. La URL debe validarse antes de publicarla.

## Requisitos e instalación

Ubuntu Server 24.04 LTS recomendado, PHP 8.1 o superior, Apache, extensión curl, HTTPS. No requiere MySQL, Composer ni Node. Se conserva el almacenamiento JSON privado de la v2. Los comandos siguientes se ejecutan en un servidor Ubuntu; no están ejecutados en su servidor.

1. Suba el ZIP a Ubuntu, descomprímalo y ejecute desde la carpeta extraída:

```bash
sudo bash ubuntu/install.sh
```

El instalador solicita el correo y una contraseña de 14 a 72 bytes para crear la cuenta de administrador, sin exponer la contraseña en argumentos de proceso. Si ya existe `/etc/fpdual/config.php`, conserva las credenciales y datos. Copia la aplicación a `/var/www/fpdual`, prepara `/var/lib/fpdual` (www-data, 0700) y crea la configuración inicial privada (root:www-data, 0640). En instalaciones nuevas solicita el dominio del virtual host, habilita Apache y ofrece configurar HTTPS mediante Certbot. No modifica un virtual host existente. Antes de actualizar, detenga el tráfico y haga copia de datos y configuración. No sustituya `data.json` por el seed.

2. Configure DNS para que el dominio resuelva al servidor. Para Let’s Encrypt con Apache, los puertos 80 y 443 deben ser accesibles. Si no activó HTTPS durante la instalación, ejecute `sudo certbot --apache -d TU-DOMINIO --redirect` tras instalar certbot y python3-certbot-apache. Si usa Cloudflare Tunnel, configure un origen HTTPS válido o un proxy de confianza que establezca HTTPS para PHP. No confíe en cabeceras de visitantes ni desactive la exigencia HTTPS. Evite registrar parámetros de consulta de enlaces de autenticación. La instalación del túnel y DNS son tareas de infraestructura externas.

3. Acceda a la aplicación con las credenciales creadas en el instalador. En **Configuración → Correo transaccional y acceso público**, indique la URL HTTPS real, nombre y correo del remitente, correo de respuesta y API key de Resend. Confirme su contraseña actual para guardar. La clave nunca se vuelve a mostrar en el formulario: dejarla vacía conserva la guardada; se puede sustituir o eliminar. La eliminación desactiva el registro público. Envíe una prueba al administrador. La aceptación por Resend no acredita entrega final. Configure el dominio de envío en https://resend.com/domains y cree una clave restringida a envío en https://resend.com/api-keys. Desactive el seguimiento de enlaces de autenticación en Resend. No incluya datos de alumnos en correos.

4. En **Configuración → Cuenta de administración** se pueden modificar correo y contraseña, confirmando la contraseña actual; el cambio invalida sesiones y enlaces de recuperación del administrador. Los parámetros de correo y cuenta se guardan en el almacén privado, no en los expedientes exportados. Los valores guardados desde el panel prevalecen sobre la configuración inicial. Proteja el almacenamiento y las copias: la API key se conserva en texto legible para el proceso PHP, bajo permisos privados (no cifrado de aplicación). Para recuperar al administrador si pierde acceso al correo, un técnico del servidor debe intervenir fuera de línea con copia previa; deberá respetar los valores `admin_identity` y `admin_override` en data.json y eliminar tokens pendientes.

5. En el panel configure identidad, calendario y grupos, curso activo, subvenciones, enlace a Conecta FP Canarias y aviso de privacidad validado por el centro/DPD. Habilite separadamente la manifestación de interés y el registro. El registro requiere configuración de correo y URL (no prueba de entrega). No se necesita editar PHP para estas opciones. No utilice datos reales hasta contar con autorización y revisión de protección de datos.

## Registro, verificación y recuperación

- Registro público: datos básicos, NIF, persona responsable, email y grupo/plazas orientativas. La contraseña se elige al verificar el correo. Un enlace de verificación de un solo uso caduca a las 24 h. Confirmar el enlace requiere POST para evitar activaciones por previsualizadores. Si caduca, repetir el registro. Tras verificar se notifica al administrador; en Registros se aprueba o descarta. Se puede crear una empresa nueva o vincular una existente solo tras comprobar identidad y autorización; el email verificado no acredita la representación. No se permite acceso hasta aprobación. Las plazas quedan sin confirmar. Un registro verificado pendiente puede descartarse para permitir una nueva solicitud.
- Invitaciones: desde ficha empresarial, el centro envía por Resend un enlace de un solo uso (7 días). El enlace puede copiarse manualmente si el envío falla. La empresa establece su contraseña.
- Recuperación: tanto empresas activas como administrador pueden solicitar un enlace de 30 minutos y un solo uso. La respuesta no revela si la cuenta existe. El enlace nuevo invalida el anterior. Tras cambiar contraseña se invalidan las sesiones anteriores. Una cuenta revocada no puede recuperar acceso. Si no llega el correo, revisar spam y contactar con el centro; el administrador puede consultar errores de Resend en logs sin registrar claves ni enlaces.
- Límites: máximo 4 solicitudes por correo y 20 por IP por hora y tipo de operación; los intentos de login tienen su propio límite. Contraseñas almacenadas mediante password_hash, tokens mediante SHA-256. Cookies seguras bajo HTTPS, CSRF y renovación de sesión. El envío es síncrono y puede tardar algunos segundos; no hay cola ni reintento automático, ni webhooks de entrega. Los mensajes genéricos de recuperación y registro no distinguen fallos de transporte. Se puede repetir la petición respetando límites. Los registros verificados pendientes requieren revisión y limpieza administrativa.

## Flujo del centro

- **Cursos:** crear curso nuevo, definir uno o varios grupos, objetivo de alumnado, horas y fechas. Seleccionar en Configuración qué curso aparece en la landing. Los cursos nuevos no heredan plazas ni alumnos. Desde Empresas se pueden vincular entidades existentes sin duplicar el directorio maestro. En esta versión cada entidad se vincula a un único grupo por curso académico.
- **Empresas:** buscar por nombre/localidad/sector y estado, abrir ficha, editar contactos, crear tutores y centros de trabajo, definir plazas solicitadas y confirmadas, fecha de seguimiento, notas internas e historial. Preparar correo (cliente local o copia manual); incluye las ayudas únicamente para empresas privadas. Las invitaciones y mensajes de cuenta se envían mediante Resend; los correos comerciales de prospección siguen siendo manuales.
- **Agenda:** lista por fecha de seguimiento con avisos visuales de vencimiento.
- **Alumnado:** completar fichas o añadir alumnado; seleccionar grupo, empresa, centro de trabajo y tutor. El servidor rechaza asignaciones por encima de las plazas confirmadas y combinaciones incoherentes. Los tutores y centros se crean primero en la ficha empresarial.
- **Documentos:** subir PDF, DOCX, XLSX, ODT, ODS, PNG o JPEG (máx. 10 MB), clasificar por tipo, curso y empresa, y decidir si se comparte. Los documentos generales son privados del centro. Descargar archivo individual, expediente de empresa o curso completo (ZIP con CSV, JSON y archivos). Máximo 30 MB de archivos por ZIP; para expedientes superiores, descargar por empresa o archivo individual. Los ZIP del centro incluyen nombres y observaciones del alumnado, por lo que deben custodiarse como datos personales. Se incluye enlace al modelo oficial de convenio; no se generan documentos oficiales automáticamente ni se realizan firmas digitales.
- **Cierre:** archivar el curso bloquea cambios en sus registros y conserva una instantánea de las fichas empresariales y tutores. Es posible reabrirlo administrativamente. Las cuentas empresariales son permanentes; pueden mantener su ficha maestra y colaborar en nuevos cursos. El histórico cerrado no cambia por modificaciones de la ficha maestra.


## Copias y operación

Con el tráfico detenido, realizar copia protegida de `/var/lib/fpdual` y `/etc/fpdual/config.php`; los ZIP de expedientes no son copias restaurables. No exponga los datos ni la API key. El sistema conserva las limitaciones de v2: un grupo por empresa y curso, sin envío masivo, sin firma electrónica ni generación automática de modelos oficiales, sin migración del HTML v1, sin auditoría independiente. Mantenga actualizaciones de Ubuntu/PHP y supervise almacenamiento y logs. No se ha instalado en el servidor real ni se ha enviado correo real sin credenciales. La prueba local usa un buzón simulado exclusivamente en loopback con HTTPS desactivado; no use esa modalidad en producción.

## Pruebas de la versión 3.0

PHP 8.4, servidor HTTP local y buzón simulado: registro, verificación, bloqueo antes de aprobación, aprobación, acceso empresarial, rechazo de acceso al panel de registros, recuperación de contraseña empresarial y del administrador, rechazo del token reutilizado, invalidación de sesiones previas y nuevo acceso. Sintaxis PHP y del instalador verificadas. Pendiente prueba con Resend real, Apache/Ubuntu de destino y certificado HTTPS.

### Nota de actualización 3.1
Los grupos de versiones anteriores sin metadatos de ciclo conservan su denominación; el administrador debe revisar y clasificar esos grupos al editarlos. No se cambia el ciclo ni el nivel de grupos ya clasificados con alumnado o empresas vinculadas.

## Novedades 3.2
Configuración de Resend y URL desde el panel, cambio de cuenta de administración y creación inicial por autoinstalador. El modo de prueba sigue reservado a configuración técnica local. Las rutas, HTTPS y permisos no se modifican desde la web por seguridad.

## 3.3 — Campañas y recordatorios (Ubuntu)

Actualización desde 3.2: descomprima el nuevo paquete y ejecute `sudo bash ubuntu/install.sh`. No elimina el almacén ni modifica el administrador existente. Antes de actualizar, haga una copia privada de `/var/lib/fpdual` y `/etc/fpdual` (detenga temporalmente el temporizador y el acceso web para una copia coherente). En un LXC debe estar disponible systemd. El instalador activa `fpdual-worker.timer`, que ejecuta la cola cada minuto como www-data. Diagnóstico: `systemctl status fpdual-worker.timer`, `systemctl list-timers fpdual-worker.timer`, `journalctl -u fpdual-worker.service -n 50`. Para detener: `sudo systemctl disable --now fpdual-worker.timer`.

1. Configure Resend, remitente verificado, URL pública HTTPS y privacidad en el panel. Publique la landing. En Campañas configure envío automático, límite diario (1–100 intentos, 20 inicialmente), días naturales de seguimiento, resumen diario y antelación de fechas. Todo permanece desactivado inicialmente.
2. Los correos publicados del directorio NO se consideran autorizados. Registre la evidencia del consentimiento específico de cada dirección desde la ficha empresarial. El registro voluntario ofrece una casilla independiente y opcional; tras verificar correo y aprobar la cuenta, se conserva el permiso si la dirección pertenece a la ficha. Las bajas globales prevalecen. No active envíos de captación no solicitados (política de Resend: https://resend.com/legal/acceptable-use).
3. Seleccione curso, localidad, tipo y grupo. Cree un borrador con asunto, texto y seguimiento opcional. Puede usar las variables indicadas. Revise los destinatarios; los no autorizados aparecen como contacto manual. Use el botón para programar llamadas o visitas pendientes. Si cambia permisos, cree un nuevo borrador para recalcular destinatarios.
4. Confirme explícitamente y active la campaña, opcionalmente con fecha y hora de inicio. Solo se permite una campaña activa por curso. Puede pausar, reanudar, cancelar u omitir destinatarios. El trabajador revalida el curso, estado, permiso y baja antes de cada envío. Se contabiliza como máximo un intento por minuto y el límite diario es global para campañas (no para el correo transaccional o el resumen). El seguimiento es opcional, máximo uno por campaña/destinatario, se cancela si cambia a estado no elegible o consta un contacto nuevo. Registre las respuestas manualmente en la ficha. El sistema no lee buzones ni detecta respuestas automáticamente.
5. El resumen diario al administrador incluye contactos pendientes/vencidos, formalizaciones pendientes, registros verificados pendientes y fechas de inicio/fin próximas de los grupos. Zona horaria Atlantic/Canary. El resumen está limitado a 300 líneas y la agenda conserva el detalle. No envía un recordatorio de resumen si no hay tareas. Si el resultado de un envío es incierto, se registra y no se reintenta automáticamente para evitar duplicados; revise Resend antes de crear otro envío.
6. Cada campaña añade un enlace de baja, con confirmación, que excluye la dirección de campañas posteriores de todos los cursos. No afecta a correos imprescindibles de cuenta. La aceptación por Resend no significa entrega; no incluye webhooks, métricas de apertura ni rebotes automáticos. Gestione rebotes/quejas desde Resend y aplique la baja global. El módulo no sustituye la revisión del responsable de protección de datos. No se envían campañas automáticamente por instalar la aplicación.

Pruebas de esta versión: sintaxis PHP y Bash; prueba de cola con buzón simulado, sin correos reales: ausencia de autorización, envío autorizado, programación de seguimiento, resumen único diario, baja global, respuesta registrada y cierre de curso. Pendiente: envío real con Resend, ejecución systemd en Ubuntu y prueba integral de producción.

## 3.4 — Altas manuales e importación masiva

En Empresas, el administrador puede dar de alta empresas individuales o abrir **Importar empresas en bloque**. Seleccione un curso abierto y un grupo. Descargue la plantilla CSV o pegue una tabla con cabeceras desde Excel, LibreOffice o Google Sheets. Los formatos admitidos son CSV, TSV y TXT delimitado; los libros XLSX deben guardarse como CSV UTF-8 o copiarse como tabla. Límite de 2 MB y 2.000 registros por lote. Cabeceras: Empresa (obligatoria), NIF, Localidad, Domicilio, Sector, Responsable, Correo, Teléfono, Tipo (Empresa, Organismo o Asociación; por defecto Empresa). Se aceptan sinónimos habituales de las cabeceras, comillas y delimitadores coma, punto y coma o tabulación. Para varios correos use comas dentro de una celda entrecomillada cuando el delimitador sea coma.

La vista previa, válida durante 30 minutos, no modifica el directorio. Clasifica altas, vinculaciones de empresas existentes de otros cursos, duplicados e incidencias. Se comprueban NIF, nombre/localidad normalizados y correo; las coincidencias ambiguas no se fusionan. Confirme únicamente las filas válidas. Si cambia la clasificación durante la revisión, se exige nueva confirmación. Las empresas existentes conservan sus datos, cuentas, tutores, sedes, plazas y asignaciones; las altas nuevas tienen estado Identificada, cero plazas y ningún permiso para campañas. No se crean cuentas empresariales ni se envía correo por importar. El sistema mantiene un único grupo por empresa y curso. Las filas erróneas se pueden corregir en el archivo y volver a importar sin duplicar las filas ya incorporadas. El alta manual también comprueba duplicados; use Reutilizar empresas de otros cursos si la empresa ya existe.

Actualización: copia privada del almacén y configuración, y `sudo bash ubuntu/install.sh` desde el paquete nuevo. Pruebas locales: análisis CSV/TSV, BOM, Windows-1252, columnas incorrectas, duplicados, coincidencias ambiguas, reutilización, reimportación y conservación de fichas y permisos; prueba de regresión de campañas con buzón simulado. Pendiente instalación en Ubuntu real y envío real mediante Resend.

## 3.5 — Oferta FEOE del centro

El catálogo de creación y edición de grupos admite exclusivamente: 2.º CFGB Servicios Administrativos; 1.º y 2.º CFGM Gestión Administrativa; 1.º y 2.º CFGS Administración y Finanzas; 2.º CFGS Asistencia a la Dirección. La validación se realiza también en el servidor. No se ofrecen 1.º de Grado Básico ni 1.º de Asistencia a la Dirección. Esta selección responde a la organización del centro, no pretende establecer una regla general de la normativa. Se pueden crear varios grupos por combinación con etiquetas diferentes. La instalación nueva conserva el grupo inicial de 28 estudiantes de 1.º de Gestión Administrativa; los otros cinco se crean desde Cursos cuando se conozcan sus datos, sin inventar alumnado, horas o fechas. No se eliminan automáticamente grupos anteriores que no cumplan el nuevo catálogo: deberán revisarse antes de publicar la oferta. La limitación anterior de una empresa por grupo y curso sigue vigente.

## 3.6 — Tres paneles independientes
- Menú lateral adaptable a móviles. Portal de empresas con rutas separadas: Inicio, Datos, Centros de trabajo, Tutores de empresa, Colaboraciones, Documentación y Cuenta.
- En administración, `Tutores docentes` permite invitar por Resend, indicar el nombre y correo y asignar uno o varios grupos del curso seleccionado. El enlace de activación de siete días también se muestra para compartirlo de forma segura. Para cursos nuevos, se asignan los permisos explícitamente. La recuperación de contraseña usa el sistema existente.
- El docente accede únicamente a los cursos/grupos autorizados, gestiona el seguimiento y las plazas de las empresas vinculadas, registra alumnado y asignaciones, y consulta/aporta documentos del grupo. Puede descargar un ZIP de su grupo. Los documentos generales del centro y las configuraciones globales quedan fuera de su alcance. Los cursos cerrados son de consulta. Los documentos de empresa ya existentes se vinculan al grupo de la colaboración; no se permite trasladarla a otro grupo si contiene documentos.
- El administrador conserva la gestión global de altas de empresas, campañas, configuración, permisos y compartición de documentos. No se ha cambiado la estructura original de una colaboración por empresa y curso; la asignación simultánea de una empresa a varios grupos del mismo curso requiere una migración posterior.
- Actualización: realizar copia de `/var/lib/fpdual` y `/etc/fpdual`, descomprimir y ejecutar `sudo bash ubuntu/install.sh`. Se mantienen credenciales y datos. Las rutas nuevas de PHP se instalan automáticamente; los archivos de vista no pueden ejecutarse directamente.
- Pruebas locales: invitación de tutor, acceso, separación de roles/grupos, modificación autorizada de alumnos y seguimiento, navegación empresarial y regresiones de campañas/importaciones. No se han utilizado credenciales reales de Resend ni ejecutado el instalador en el servidor definitivo.
