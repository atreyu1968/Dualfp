# FP Dual Conecta A&G 3.8 · Ubuntu y Resend

**Revisión del 26/11/2026:** consulte [AUDITORIA.md](AUDITORIA.md) para conocer los resultados de las pruebas, las correcciones y las verificaciones pendientes en Ubuntu.

## 1. Instalación desde cero: Ubuntu Server 24.04 LTS

Estas instrucciones parten de un Ubuntu recién instalado, sin actualizar y sin Git, curl ni sudo. Se requiere una máquina virtual o contenedor LXC con systemd, conexión a Internet, DNS funcional, acceso a los repositorios de Ubuntu y al repositorio público de GitHub. Se recomienda 2 vCPU, 2 GB de RAM y 20–30 GB de disco. Use un servidor dedicado: la actualización detiene Apache, por lo que también afectaría a otros sitios alojados en él.

### Paso 1. Entrar como root

En la consola de Proxmox, inicie sesión como root. Si utiliza una cuenta Ubuntu con permisos sudo, ejecute `sudo -i`. Si sudo no existe, acceda como root por consola (o `su -` si conoce su contraseña). No ejecute los siguientes comandos sin privilegios de administración.

### Paso 2. Actualizar Ubuntu e instalar herramientas básicas

```bash
apt-get update
apt-get upgrade -y
apt-get install -y ca-certificates curl git
```

Si existe `/var/run/reboot-required`, reinicie la máquina o el contenedor desde Proxmox y vuelva a entrar como root antes de continuar. No ejecute el instalador en un sistema con actualizaciones interrumpidas.

### Paso 3. Descargar y ejecutar el instalador

```bash
cd /root
curl -fL --retry 3 https://raw.githubusercontent.com/atreyu1968/Dualfp/main/install.sh -o install-fpdual.sh
bash -n install-fpdual.sh
# Revise el contenido antes de ejecutarlo:
cat install-fpdual.sh
bash install-fpdual.sh
```

El instalador descarga la rama main, comprueba los archivos e instala Apache, PHP y las dependencias. Solicitará un correo de administrador, contraseña de 14–72 bytes (se solicita dos veces), dominio sin https:// y si desea configurar Let's Encrypt. No solicita la API key de Resend; se configura más tarde desde el navegador. Si ya existe una instalación completa, use el actualizador del apartado 2. Si la primera instalación se interrumpió antes de preparar todos los archivos de Apache y systemd, vuelva a ejecutar el instalador: conserva el administrador existente. Si ya están presentes todos los archivos pero falló la activación del temporizador, compruebe Apache y ejecute systemctl daemon-reload y systemctl enable --now fpdual-worker.timer tras resolver el error.

Alternativa con Git, después del paso 2:

```bash
cd /root
git clone https://github.com/atreyu1968/Dualfp.git
cd Dualfp
bash install.sh
```

### Paso 4. Incorporar el directorio privado de empresas (opcional, solo antes de la primera instalación)

Transfiera por un canal privado el archivo fpdual_directorio_privado.json a /root y proteja sus permisos. En lugar de ejecutar el último comando del paso 3, utilice:

```bash
chmod 600 /root/fpdual_directorio_privado.json
bash /root/install-fpdual.sh --seed /root/fpdual_directorio_privado.json
```

No publique este JSON en GitHub. El repositorio público incluye un seed vacío. Si no aporta el fichero privado, la instalación nueva parte de cero empresas y 28 fichas vacías del grupo inicial. Si ya hay datos, use el importador CSV/TSV del panel y no sobrescriba data.json.

### Paso 5. Configurar el acceso HTTPS

El dominio debe apuntar al servidor mediante DNS. Si usa Let's Encrypt directamente, habilite el acceso externo a los puertos 80 y 443, y compruebe que no hay conflictos con otros servidores. Si utiliza Cloudflare Tunnel, siga la configuración del túnel y del origen HTTPS de confianza de su infraestructura: el instalador no crea el túnel. No desactive la exigencia HTTPS ni confíe en cabeceras arbitrarias de los visitantes.

### Paso 6. Verificar los servicios

```bash
systemctl status apache2 --no-pager
apache2ctl configtest
php -v
systemctl status fpdual-worker.timer --no-pager
systemctl list-timers fpdual-worker.timer
journalctl -u fpdual-worker.service -n 50 --no-pager
```

Abra la URL HTTPS de su dominio en el navegador. Inicie sesión con el administrador creado durante la instalación. Configure identidad institucional, URL pública, Resend (clave y remitente verificado), cursos, grupos, tutores, ayudas y privacidad. Realice un envío de prueba, revise la protección de datos y solo entonces habilite registro, campañas y recordatorios. La instalación por sí sola no envía campañas.

## 2. Actualizar una instalación existente desde GitHub

Descargue siempre el actualizador vigente, no reutilice una copia antigua. Desde una terminal interactiva como root:

```bash
cd /root
apt-get update
apt-get install -y ca-certificates curl git
curl -fL --retry 3 https://raw.githubusercontent.com/atreyu1968/Dualfp/main/update.sh -o update-fpdual.sh
bash -n update-fpdual.sh
cat update-fpdual.sh
bash update-fpdual.sh
```

Confirme la ventana de mantenimiento con s. El actualizador obtiene la revisión de main, comprueba los archivos y la sintaxis PHP, detiene temporalmente el temporizador, espera a que termine el trabajador y detiene Apache. Realiza una copia comprimida privada y verifica que puede leerse antes de sustituir los archivos. Conserva /etc/fpdual y /var/lib/fpdual, incluyendo credenciales, Resend, alumnado, empresas, expedientes, campañas y permisos. Conserva también la configuración del virtual host. No realiza una actualización general de Ubuntu. Si falla al copiar o validar los archivos, intenta restaurar el código y los archivos del trabajador desde la copia; si falla la recuperación, deja los servicios detenidos y avisa. Si falla al reiniciar un servicio tras sustituir el código, el actualizador también intenta detener los servicios y recuperar el código anterior; no se restaura automáticamente el estado de datos. El script es compatible con la estructura de archivos 3.7 y deberá revisarse cuando se añadan dependencias, archivos o migraciones en futuras versiones.

Las copias están en /var/backups/fpdual/ (acceso root). Incluyen datos personales y secretos: no compartir ni publicar. Controle el espacio libre y conserve copias externas seguras. El actualizador no elimina copias antiguas ni habilita servicios que estuvieran detenidos. Compruebe la instalación al terminar:

```bash
cat /usr/local/lib/fpdual/revision
apache2ctl configtest
systemctl status apache2 fpdual-worker.timer --no-pager
journalctl -u fpdual-worker.service -n 50 --no-pager
ls -lh /var/backups/fpdual/
```

Si aparece un error de GitHub, compruebe DNS y acceso a Internet antes de reintentar. Si falla Apache, consulte `journalctl -u apache2 -n 100 --no-pager`. Si no llega el correo, revise el dominio verificado, la clave y el remitente en Resend. No restaure un archivo de datos sobre una instalación en funcionamiento.

Los apartados siguientes documentan el funcionamiento de la aplicación y su historial. Las referencias históricas a instalar de nuevo sobre una versión anterior quedan sustituidas por el procedimiento de actualización del apartado 2.

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

- **Cursos:** crear curso nuevo, definir uno o varios grupos, objetivo de alumnado, horas y fechas. Seleccionar en Configuración qué curso aparece en la landing. Los cursos nuevos no heredan plazas ni alumnos. Desde Empresas se pueden vincular entidades existentes sin duplicar el directorio maestro. Desde 3.7 cada entidad puede vincularse a varios grupos del mismo curso académico, con plazas y expedientes independientes.
- **Empresas:** buscar por nombre/localidad/sector y estado, abrir ficha, editar contactos, crear tutores y centros de trabajo, definir plazas solicitadas y confirmadas, fecha de seguimiento, notas internas e historial. Preparar correo (cliente local o copia manual); incluye las ayudas únicamente para empresas privadas. Las invitaciones y mensajes de cuenta se envían mediante Resend; los correos comerciales de prospección siguen siendo manuales.
- **Agenda:** lista por fecha de seguimiento con avisos visuales de vencimiento.
- **Alumnado:** completar fichas o añadir alumnado; seleccionar grupo, empresa, centro de trabajo y tutor. El servidor rechaza asignaciones por encima de las plazas confirmadas y combinaciones incoherentes. Los tutores y centros se crean primero en la ficha empresarial.
- **Documentos:** subir PDF, DOCX, XLSX, ODT, ODS, PNG o JPEG (máx. 10 MB), clasificar por tipo, curso y empresa, y decidir si se comparte. Los documentos generales son privados del centro. Descargar archivo individual, expediente de empresa o curso completo (ZIP con CSV, JSON y archivos). Máximo 30 MB de archivos por ZIP; para expedientes superiores, descargar por empresa o archivo individual. Los ZIP del centro incluyen nombres y observaciones del alumnado, por lo que deben custodiarse como datos personales. Se incluye enlace al modelo oficial de convenio; no se generan documentos oficiales automáticamente ni se realizan firmas digitales.
- **Cierre:** archivar el curso bloquea cambios en sus registros y conserva una instantánea de las fichas empresariales y tutores. Es posible reabrirlo administrativamente. Las cuentas empresariales son permanentes; pueden mantener su ficha maestra y colaborar en nuevos cursos. El histórico cerrado no cambia por modificaciones de la ficha maestra.


## Copias y operación

Con el tráfico detenido, realizar copia protegida de `/var/lib/fpdual` y `/etc/fpdual/config.php`; los ZIP de expedientes no son copias restaurables. No exponga los datos ni la API key. Las limitaciones actuales incluyen ausencia de firma electrónica, generación automática de modelos oficiales, lectura automática de respuestas y migración del HTML v1. Las campañas autorizadas y la colaboración multigrupo están disponibles. No se ha realizado una auditoría independiente. Mantenga actualizaciones de Ubuntu/PHP y supervise almacenamiento y logs. No se ha instalado en el servidor real ni se ha enviado correo real sin credenciales. La prueba local usa un buzón simulado exclusivamente en loopback con HTTPS desactivado; no use esa modalidad en producción.

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

La vista previa, válida durante 30 minutos, no modifica el directorio. Clasifica altas, vinculaciones de empresas existentes de otros cursos, duplicados e incidencias. Se comprueban NIF, nombre/localidad normalizados y correo; las coincidencias ambiguas no se fusionan. Confirme únicamente las filas válidas. Si cambia la clasificación durante la revisión, se exige nueva confirmación. Las empresas existentes conservan sus datos, cuentas, tutores, sedes, plazas y asignaciones; las altas nuevas tienen estado Identificada, cero plazas y ningún permiso para campañas. No se crean cuentas empresariales ni se envía correo por importar. Desde 3.7 la detección de duplicados en la vinculación se realiza por empresa, curso y grupo; se puede reutilizar la misma ficha para otro grupo sin copiar plazas. Las filas erróneas se pueden corregir en el archivo y volver a importar sin duplicar las filas ya incorporadas. El alta manual también comprueba duplicados; use Reutilizar empresas de otros cursos si la empresa ya existe.

Actualización: copia privada del almacén y configuración, y `sudo bash ubuntu/install.sh` desde el paquete nuevo. Pruebas locales: análisis CSV/TSV, BOM, Windows-1252, columnas incorrectas, duplicados, coincidencias ambiguas, reutilización, reimportación y conservación de fichas y permisos; prueba de regresión de campañas con buzón simulado. Pendiente instalación en Ubuntu real y envío real mediante Resend.

## 3.5 — Oferta FEOE del centro

El catálogo de creación y edición de grupos admite exclusivamente: 2.º CFGB Servicios Administrativos; 1.º y 2.º CFGM Gestión Administrativa; 1.º y 2.º CFGS Administración y Finanzas; 2.º CFGS Asistencia a la Dirección. La validación se realiza también en el servidor. No se ofrecen 1.º de Grado Básico ni 1.º de Asistencia a la Dirección. Esta selección responde a la organización del centro, no pretende establecer una regla general de la normativa. Se pueden crear varios grupos por combinación con etiquetas diferentes. La instalación nueva conserva el grupo inicial de 28 estudiantes de 1.º de Gestión Administrativa; los otros cinco se crean desde Cursos cuando se conozcan sus datos, sin inventar alumnado, horas o fechas. No se eliminan automáticamente grupos anteriores que no cumplan el nuevo catálogo: deberán revisarse antes de publicar la oferta. En la versión 3.5 seguía vigente la limitación de una colaboración por empresa y curso; se elimina en 3.7.

## 3.6 — Tres paneles independientes
- Menú lateral adaptable a móviles. Portal de empresas con rutas separadas: Inicio, Datos, Centros de trabajo, Tutores de empresa, Colaboraciones, Documentación y Cuenta.
- En administración, `Tutores docentes` permite invitar por Resend, indicar el nombre y correo y asignar uno o varios grupos del curso seleccionado. El enlace de activación de siete días también se muestra para compartirlo de forma segura. Para cursos nuevos, se asignan los permisos explícitamente. La recuperación de contraseña usa el sistema existente.
- El docente accede únicamente a los cursos/grupos autorizados, gestiona el seguimiento y las plazas de las empresas vinculadas, registra alumnado y asignaciones, y consulta/aporta documentos del grupo. Puede descargar un ZIP de su grupo. Los documentos generales del centro y las configuraciones globales quedan fuera de su alcance. Los cursos cerrados son de consulta. Los documentos de empresa ya existentes se vinculan al grupo de la colaboración; no se permite trasladarla a otro grupo si contiene documentos.
- El administrador conserva la gestión global de altas de empresas, campañas, configuración, permisos y compartición de documentos. En 3.6 se conservaba la estructura original de una colaboración por empresa y curso. La versión 3.7 admite colaboraciones múltiples.
- Actualización: realizar copia de `/var/lib/fpdual` y `/etc/fpdual`, descomprimir y ejecutar `sudo bash ubuntu/install.sh`. Se mantienen credenciales y datos. Las rutas nuevas de PHP se instalan automáticamente; los archivos de vista no pueden ejecutarse directamente.
- Pruebas locales: invitación de tutor, acceso, separación de roles/grupos, modificación autorizada de alumnos y seguimiento, navegación empresarial y regresiones de campañas/importaciones. No se han utilizado credenciales reales de Resend ni ejecutado el instalador en el servidor definitivo.

## 3.7 — Colaboración de una empresa con varios grupos

- **Administrador → Empresas:** en «Vincular empresas a otro grupo o curso», seleccione una empresa existente y el grupo adicional. La ficha jurídica y de contacto, los tutores de empresa, las sedes y las cuentas de acceso siguen siendo únicos para la organización. La lista muestra cada colaboración con su grupo; el indicador de empresas del cuadro de mando cuenta entidades distintas.
- **Administrador → Ficha de empresa:** cada colaboración tiene su propio estado, plazas solicitadas/confirmadas, historial, seguimiento y documentos. Las nuevas vinculaciones no copian las plazas previas. No se puede repetir la misma combinación de empresa, curso y grupo. Para mover una colaboración a otro grupo debe estar libre de documentos, alumnado incompatible y campañas pendientes, y no puede existir ya una colaboración con el grupo de destino.
- **Empresa → Mis colaboraciones:** seleccione curso y grupo para gestionar la oferta. La página Inicio suma las plazas de sus grupos. Puede solicitar plazas para otro grupo del curso al que ya está vinculada; el centro deberá confirmarlas. No se confirman plazas por registrar una oferta.
- **Empresa → Documentación:** seleccione grupo para aportar y descargar documentos compartidos. Hay una descarga ZIP por colaboración y otra para todas las colaboraciones propias de ese curso. Los expedientes empresariales no incluyen alumnado, notas internas ni otras empresas.
- **Tutor docente:** solo puede gestionar colaboraciones y alumnos de sus grupos autorizados. No puede acceder a documentos de otro grupo aunque pertenezcan a la misma empresa. Los tutores y sedes empresariales son comunes a todos los grupos. La asignación comprueba plazas y pertenencia al grupo en el servidor.
- **Importación:** se reutilizan fichas existentes para nuevos grupos del curso. No se crean fichas duplicadas ni se alteran plazas existentes.
- **Campañas:** autorizaciones comunes por empresa y correo. El filtro por grupo utiliza la colaboración correspondiente. Sin filtro, se selecciona una sola colaboración elegible por empresa dentro de cada campaña para evitar duplicar destinatarios; cree una campaña específica si desea un mensaje para otro grupo. Los recordatorios identifican empresa y grupo.
- **Histórico:** se conservan los identificadores antiguos y sus referencias. Las colaboraciones adicionales tienen identificador propio formado por el identificador de empresa y un sufijo aleatorio separado por ~. No es necesario reimportar ni migrar destructivamente el archivo data.json. El cierre conserva instantáneas de cada colaboración.
- **Capacidad:** las plazas confirmadas se controlan por grupo; no se ha establecido una capacidad global por sede/tutor empresarial ni un control de solapamiento horario entre grupos. El administrador debe comprobar la disponibilidad real de la empresa antes de confirmar.

### Actualización y compatibilidad

Utilice el apartado 2 de este README y descargue de nuevo update.sh desde main. El actualizador realiza copia de los datos y del código antes de sustituir los archivos. Los datos anteriores se abren directamente. Tras crear nuevas colaboraciones multigrupo, no debe ejecutar código 3.6 sobre esos datos: para un retroceso completo, restaure una copia coherente anterior a la actualización durante mantenimiento, teniendo en cuenta que se perderían cambios posteriores. Conserve una copia adicional de los datos actuales antes de cualquier restauración.

### Pruebas

```bash
php tests/campaigns_test.php
php tests/imports_test.php
php tests/multigroup_test.php
```

La revisión local del código 3.7 incluye 124 comprobaciones HTTP, 21 de campañas/importación y 17 de colaboración multigrupo. Se han verificado las huellas de los archivos PHP contra el repositorio de desarrollo; las pruebas utilizan datos ficticios y correo simulado. No acredita la instalación definitiva en Ubuntu, el funcionamiento de TLS/DNS ni la entrega real mediante Resend.

## 3.8 — Buzón empresarial Resend

### Configuración inicial (solo administrador)

1. Actualice desde GitHub con update.sh; para Ubuntu nuevo siga el apartado de instalación desde cero. El nuevo módulo se instala junto al código y reutiliza el trabajador systemd. La recepción está deshabilitada por defecto.
2. En Resend, configure una dirección de recepción. Para probar sin tocar DNS, puede usar la dirección del dominio asignado por Resend (resend.app). Si dispone de correo institucional en su dominio principal, utilice un subdominio dedicado a recepción, por ejemplo respuestas.example.org. No sustituya los registros MX institucionales. Consulte https://resend.com/features/inbound y los registros DNS exactos que indique Resend para su dominio.
3. En el panel de Resend, cree un webhook para el evento email.received con la URL HTTPS que muestra **Buzón empresarial → Configuración de recepción Resend**, de la forma https://SU-DOMINIO/index.php?v=inbound_webhook. Copie el secreto de firma whsec_ de ese webhook. Mantenga el reloj del servidor sincronizado: se rechazan firmas con una desviación superior a cinco minutos.
4. Cree una API key independiente con acceso suficiente para GET /emails/receiving/:email_id. Una clave limitada exclusivamente a envío no permite recuperar mensajes. Si Resend requiere acceso completo, utilice una cuenta/proyecto dedicado, custodie la clave y rote/revoque cuando corresponda. Mantenga la clave de envío existente separada.
5. Introduzca la dirección exacta de recepción, la clave de recepción y el secreto de firma en el panel, confirme con la contraseña actual y active la recepción. Los secretos no vuelven a mostrarse: dejar el campo vacío conserva el anterior; se pueden borrar ambas claves al desactivar el módulo. Se guardan en el almacenamiento privado de la aplicación, protegido mediante permisos de archivo; todavía no hay cifrado adicional de secretos.
6. Envíe un mensaje de prueba a la dirección elegida. Consulte el registro de entregas del webhook en Resend: debe devolver HTTP 200 para un evento válido. En el buzón aparecerá primero la cabecera; el trabajador recupera después el texto mediante la API. Compruebe systemctl status fpdual-worker.timer y journalctl -u fpdual-worker.service -n 50 --no-pager. Si hay errores de recuperación, revise la clave y utilice Reintentar recuperación. Se realizan hasta cinco intentos con espera creciente, una recuperación por ciclo.
7. Mientras la recepción esté activa, los nuevos correos de campañas usan como Reply-To esa dirección. Los correos enviados anteriormente no cambian. Las demás comunicaciones transaccionales conservan su Reply-To habitual.

### Operación diaria

- Bandejas Pendientes, Vinculados, Archivados y Todos. Se muestra remitente, asunto, fecha local de recepción, estado, texto y resultado DMARC comunicado por Resend. No se ejecuta HTML ni se cargan imágenes remotas.
- Se sugieren empresas por coincidencia exacta del correo con el directorio. La asociación con una colaboración de curso y grupo se confirma manualmente mediante una nota revisada. El tutor verá esa nota en el seguimiento de su grupo, no el correo completo. No se concede acceso, se confirman plazas ni se cambian estados académicos automáticamente.
- Los mensajes nuevos procedentes de una dirección registrada retienen el seguimiento automático pendiente a ese remitente. Vincular una respuesta añade historial y cancela el seguimiento anterior de esa colaboración mediante el control de historial existente. Archivar sin vincular o borrar libera la retención; otras colaboraciones pueden reanudar sus seguimientos. Revise las campañas si la respuesta afecta a más de un grupo.
- Desde un mensaje vinculado y con DMARC pass se puede redactar y confirmar una respuesta manual. No hay envío automático por IA. La respuesta se envía al remitente mostrado, con asunto editable y Reply-To del buzón, y se registra su resultado. No hay garantía de agrupación en el mismo hilo ni de entrega efectiva. Si el resultado es incierto, compruebe Resend antes de reenviar.
- El resumen diario incluye el número de mensajes pendientes, si se ha activado ese resumen. Los tutores y las empresas no tienen acceso directo al buzón administrativo.
- Los adjuntos solo se enumeran por nombre: consulte el original en Resend y suba documentos revisados al expediente correspondiente. No se descargan ni ejecutan adjuntos automáticamente.
- Límite local: 500 mensajes, 24.000 bytes de texto por mensaje, cinco respuestas manuales de hasta 10.000 bytes cada una por mensaje y 20.000 identificadores de mensajes eliminados. La API limita la descarga del cuerpo a 1 MiB. Si se alcanza el límite de almacenamiento, el webhook devuelve 503; revise las entregas y libere espacio. No se realiza borrado automático ni importación retroactiva del buzón. Borrar localmente no elimina el original en Resend, ni las notas ya vinculadas, ni las copias de seguridad.
- Trate todos los mensajes como datos no confiables. La firma Svix acredita el transporte del evento, no la identidad del representante de empresa. No introduzca datos de alumnado en respuestas de captación; revise privacidad y conservación con el centro.

### Pruebas

```bash
php tests/inbox_test.php
# En un entorno de desarrollo aislado con requests y beautifulsoup4:
python3 tests/inbox_http.py .
php tests/campaigns_test.php
php tests/imports_test.php
php tests/multigroup_test.php
```

Pruebas locales con datos ficticios: 24 comprobaciones del buzón, 25 HTTP de recepción y permisos, 124 HTTP funcionales anteriores y 38 de regresión de campañas/importación/multigrupo. El envío se simula; no se ha probado la entrega real de webhooks desde Resend ni la recuperación de cuerpos mediante una credencial real. La aceptación en Ubuntu, HTTPS y systemd sigue pendiente. Los webhooks de entrega, rebotes y quejas, el procesamiento IA y la descarga de adjuntos no forman parte de esta versión.
