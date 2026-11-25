<!-- Versión en español. English version: README.md · Versió en català: README.ca.md -->
<p align="center">
  <img src="pix/logo.png" alt="" width="280">
</p>

<h1 align="center">Gestor de transferencias de cursos</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.2.2-informational" alt="Versión">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.3-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="Licencia">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Hecho por Tresipunt"></a>
</p>

<p align="center"><b>Archivo programado de categorías de cursos entre plataformas Moodle — borrados anunciados y cancelables.</b></p>

<p align="center"><a href="README.md">🇬🇧 English</a> · <b>🇪🇸 Español</b> · <a href="README.ca.md">Català</a></p>

El Gestor de transferencias de cursos automatiza el archivo anual de categorías
sobre [Course Transfer](https://github.com/3iPunt/moodle-local_coursetransfer):
una tarea trae una categoría completa de otra plataforma una vez al año, la
guarda en tu archivo y, pasada la retención que indiques, la borra del origen.
No añade maquinaria de transferencia propia —orquesta Course Transfer— y como
borra contenido en una plataforma **remota** meses después, todo borrado se
anuncia con antelación, se ve en pantalla y se puede cancelar hasta el momento
en que ocurre.

---

## ✨ Qué hace

- **Panel de gestión** — comprobaciones de salud de los requisitos (Course
  Transfer, cron, tarea programada del plugin), una agenda cronológica
  **«Próximamente»** que junta las próximas ejecuciones con los borrados
  pendientes, y tarjetas de tarea con su última ejecución, interruptor de
  activación y un «Ejecutar ahora» con salvaguardas.
- **Asistente de tarea guiado** — cinco pasos que verifican sobre la marcha:
  **probar el patrón de categoría contra el origen** antes de guardar, programar
  en lenguaje normal (con el cron como modo avanzado) y vista previa en vivo de
  las próximas ejecuciones, ver las **fechas reales** de cada borrado que
  provocará la tarea, y revisar la línea de tiempo completa antes de confirmar.
- **Pantalla de seguimiento** — qué se está trayendo ahora, qué se lanzará
  después y todo lo que se va a borrar, con un registro unificado; cada petición
  enlaza a su detalle fino en Course Transfer.
- **Nueve avisos del ciclo de vida** — campana y correo en cada hito, incluido un
  **aviso previo con enlace para cancelar** antes de borrar en el origen, un
  aviso cuando un borrado queda **retenido** por una comprobación de seguridad y
  la petición de **aprobar** el primer borrado de una tarea.
- **Candados de seguridad** — el original nunca se borra si la restauración no
  consta completada, si algún curso restaurado ya no está en el archivo o si el
  origen ha cambiado desde entonces; el primer borrado de cada tarea espera a una
  persona, y un **freno de emergencia** pausa todos los borrados de golpe (ver
  *Salvaguardas*).
- **Simulación** — qué archivaría, borraría y podaría una tarea ahora mismo, sin
  tocar nada.

## 🧭 Casos de uso

- **Archivo anual de un curso académico** — cada 1 de septiembre, traer de la
  plataforma de producción la categoría del año que acaba a una instancia de
  archivo, y retirarla de producción un mes más tarde, cuando la copia ya se ha
  revisado.
- **Liberar espacio en producción de forma predecible** — mantener en línea solo
  los años vigentes: el archivo conserva los últimos N años y anuncia los más
  antiguos como candidatos a podado, dando tiempo a excluir lo que convenga
  conservar.
- **Consolidar varios orígenes en un mismo archivo** — una tarea por plataforma
  de origen, cada una con su patrón y su retención, todas visibles en el mismo
  panel.
- **Archivar sin borrar nada** — con una retención alta (o cancelando el borrado
  cada año) se obtiene la copia anual automática mientras el original permanece
  intacto en el origen.

## ⚙️ Cómo funciona

- Una **tarea** define: la plataforma origen, una **máscara de nombre** que
  reconoce las categorías anuales, la categoría de archivo de destino, cuándo se
  ejecuta y las dos ventanas de retención.
- **Rota sola.** La máscara no es una categoría: reconoce toda la serie. En la
  ejecución del curso A la tarea archiva A−P y borra definitivamente A−P−V,
  donde **P** son los cursos que se quedan en producción y **V** los que se
  guardan en el archivo. Nadie edita la tarea en septiembre.

  | Máscara | Reconoce | Marcadores |
  |---|---|---|
  | `CAT-{YEAR}-{NEXTYEAR}` | `CAT-2025-2026` | años de cuatro cifras |
  | `CAT-{YEAR}-{NEXTYY}` | `CAT-2025-26` | mixto |
  | `CAT-{YY}-{NEXTYY}` | `CAT-25-26` | años de dos cifras |
  | `SJD{YEAR}` | `SJD2025` | un solo año |
  | `{ANY}-{YEAR}-{NEXTYEAR}` | `GINF-2025-2026`, `MED-2025-2026` | una tarea, todos los grados |

  Con P=2 y V=4, la ejecución de 2027/28 deja en producción 2027/28 y 2026/27,
  mantiene cuatro cursos en el archivo y borra definitivamente 2021/22. El
  asistente y la **vista de ciclo de vida** (`task.php?id=N`) proyectan esa
  tabla seis ejecuciones hacia delante, para no tener que calcularlo de cabeza.
- En la fecha programada la tarea lee las categorías del origen, elige el curso
  que se ha salido de la ventana de producción y pide a Course Transfer que lo
  restaure; después lo recoloca bajo la categoría de archivo elegida.
- Una restauración solo está **completada** cuando Course Transfer lo confirma;
  el borrado en el origen nunca se ejecuta antes (ver *Salvaguardas*).
- Antes de borrar en el origen se envía un **aviso previo** (días configurables)
  y se repiten todas las comprobaciones de seguridad. Si alguna falla, el
  borrado se **retiene** y se informa, en lugar de ejecutarse.
- Las categorías que llegaron al archivo **sin** la tarea son invisibles para el
  podado a propósito: es lo que protege el contenido ajeno. Adoptar una para que
  la tarea pueda borrarla es una decisión explícita, auditada y reversible desde
  la vista de ciclo de vida.
- Todo es **asíncrono**: depende del **cron** de Moodle en las dos plataformas,
  así que el cron debe estar corriendo en ambas.

## 📋 Requisitos

| Requisito | Versión |
|---|---|
| Moodle | 4.5, 5.1 o 5.3 |
| PHP | 8.1+ |
| Otros plugins | `local_coursetransfer` **2.2.1 o superior en esta plataforma** (se recomienda 2.2.3; la plataforma origen remota solo necesita sus servicios web de backend estables) |
| Cron | En marcha en **las dos** plataformas |

## 🚀 Instalación

1. Copiar el código en `local/coursetransfermanager/` (`public/local/…` en
   Moodle 5.x).
2. Completar la instalación desde **Administración del sitio › Notificaciones**
   (o `php admin/cli/upgrade.php --non-interactive`).
3. Purgar las cachés (**Administración del sitio › Desarrollo › Purgar cachés**).
4. Comprobar que las plataformas origen ya están emparejadas en Course Transfer y
   que **el cron corre en las dos plataformas**.

## 🔧 Ajustes

En **Administración del sitio › Extensiones › Extensiones locales › Gestor de
transferencias de cursos**:

| Ajuste | Efecto |
|---|---|
| **Días de aviso antes de borrar en el origen** | Con cuánta antelación se envía el aviso previo cancelable antes de un borrado programado. Por defecto: 7, mínimo 1. |
| **Días de gracia antes de podar el archivo** | Tiempo entre el anuncio de los candidatos al podado y su borrado, para poder excluirlos. Por defecto: 7, mínimo 1. |
| **Retener el borrado si el origen ha cambiado (R3)** (`holdiforiginchanged`) | Activado por defecto. Ver *Salvaguardas*. |
| **Aprobar el primer borrado de cada tarea** (`requirefirstdeletionapproval`) | Activado por defecto. Ver *Salvaguardas*. |
| **Pausar todos los borrados (freno de emergencia)** | Mientras está activo no se ejecuta ningún borrado ni podado y sus fechas se van posponiendo, de modo que quitar la pausa nunca provoca un atasco. Las restauraciones no se ven afectadas. |
| **Mes de inicio del curso académico** | Se usa para saber qué curso está en marcha cuando se ejecuta una tarea. Antes de ese mes el curso en marcha sigue siendo el anterior: en mayo de 2026 el curso es 2025/26. Por defecto: septiembre. |
| **Máximo de categorías archivadas por ejecución** | Tope para que un curso atrasado no inunde el origen de restauraciones simultáneas. |

Las ventanas de retención (**cursos en producción** y **cursos en el archivo**)
son de cada tarea, no del sitio: distintos orígenes pueden guardar distinta
cantidad de historia.

Los canales de aviso se gestionan con las preferencias de notificación estándar
de Moodle, por sitio y por usuario.

## 🛡️ Salvaguardas

La tarea habla con el origen **como su creador** (o como quien pulsa «Ejecutar
ahora»): ese usuario necesita una cuenta equivalente en el origen con las
capacidades que Course Transfer exige allí. El borrado en el origen se programa
a los **días de retención** de la tarea desde el lanzamiento, pero nunca se
ejecuta antes de que Course Transfer dé la restauración por **completada**.

Borrar en el origen es el único paso que elimina contenido que quizá no exista en
ningún otro sitio. Justo antes de ejecutarlo se hacen estas comprobaciones; si
alguna falla, el borrado queda **retenido**, se pospone y se avisa, nunca se
ejecuta en silencio:

| Salvaguarda | Qué comprueba | Ajuste |
|---|---|---|
| **R1 — restauración verificada** | Course Transfer ha dado la restauración por completada (no solo lanzada) y verificada: sin módulos ni ficheros que falten y con los cursos restaurados iguales al origen. | Siempre activa |
| **R2 — archivo intacto** | Todos los cursos de esa restauración siguen en esta plataforma, dentro de la categoría archivada. | Siempre activa |
| **Identidad utilizable** | El creador de la tarea existe y está activo: nunca se pide nada al origen con otra identidad. | Siempre activa |
| **R3 — origen sin cambios** | La categoría del origen sigue teniendo los cursos que se archivaron; cursos nuevos o desaparecidos, o un origen que no responde, retienen el borrado. El origen no informa de fechas de modificación, así que solo se compara la lista de cursos. | `holdiforiginchanged` (activado) |
| **Aprobación del primer borrado** | El primer borrado de cada tarea, y el primero tras cambiar su máscara, años u origen, espera en *Ejecuciones y borrados* a que un gestor lo apruebe. Tras aprobarlo, las salvaguardas se vuelven a comprobar. | `requirefirstdeletionapproval` (activado) |
| **Aviso previo** | Se envía un aviso cancelable antes del borrado, nunca en la misma pasada del cron. | Días de aviso (≥ 1) |
| **Freno de emergencia** | Mientras está activo no se ejecuta ningún borrado ni podado y sus fechas se posponen. | Pausar todos los borrados |

El podado del archivo tiene sus propias garantías: solo considera categorías que
la tarea trajo o adoptó, anuncia los candidatos con un periodo de gracia (se
pueden excluir) y vuelve a comprobar cada uno antes de borrarlo; el que ya no
cumple la política queda excluido para siempre. En el origen, Course Transfer
aplica sus propias protecciones (retención en la papelera, eventos de auditoría).

**Simula** una tarea (botón en su vista de ciclo de vida) para ver qué
archivaría, qué borrados se ejecutarían, quedarían retenidos o esperarían
aprobación y qué podaría, sin cambiar nada. Cada borrado, retención, aprobación,
cancelación y podado queda además como evento en los registros del sitio.

## 🗑️ Desinstalación

Al eliminar el plugin se borran sus tareas, el historial de ejecuciones y los
registros de podado. No se toca nada más: las categorías ya archivadas se quedan
donde están y no vuelve a programarse ningún borrado.

## 🛠️ Desarrollo

```bash
# Tests unitarios
vendor/bin/phpunit --testsuite local_coursetransfermanager_testsuite

# Compilación de JavaScript (tras editar amd/src)
npx grunt amd --root=local/coursetransfermanager
```

## 📄 Licencia

[GNU GPL v3 o posterior](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>
