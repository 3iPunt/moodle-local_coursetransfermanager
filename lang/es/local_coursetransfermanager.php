<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Cadenas de idioma para local_coursetransfermanager (español).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Gestor de transferencias de cursos';
$string['coursetransfermanager:managetasks'] = 'Gestionar las transferencias programadas de cursos';
$string['managetasks'] = 'Transferencias programadas';
$string['managetasks_desc'] = 'Archivo anual automático de categorías de cursos entre plataformas Moodle. Aquí ves qué se ejecutará y qué se borrará próximamente.';
$string['id'] = 'ID';
$string['type'] = 'Tipo';
$string['status'] = 'Estado';
$string['fromdate'] = 'Desde';
$string['todate'] = 'Hasta';

$string['status_error'] = 'Error';
$string['status_completed'] = 'Completada';
$string['status_success'] = 'Éxito';


$string['createtask'] = 'Nueva tarea';
$string['edittask'] = 'Editar tarea';

$string['taskname'] = 'Nombre';
$string['categorypattern'] = 'Patrón de categoría';
$string['targetcategory'] = 'Categoría destino';
$string['cronexpression'] = 'Expresión cron';
$string['retentiondays'] = 'Días de retención';
$string['destinationkeepyears'] = 'Años a mantener en destino';
$string['restoreuserdata'] = 'Restaurar usuarios';
$string['enabled'] = 'Activa';

$string['invalidcron'] = 'La expresión cron debe tener 5 partes';

$string['actions'] = 'Acciones';
$string['executions'] = 'Ejecuciones';

$string['deletetask'] = 'Borrar tarea';
$string['taskdeleted'] = 'Tarea borrada correctamente';
$string['confirmdeletetask'] = '¿Seguro que quieres borrar la tarea "{$a}"? También se borrará su histórico de ejecuciones.';

$string['requestid'] = 'Request ID';
$string['origincategoryid'] = 'ID categoría origen';
$string['origincategoryname'] = 'Categoría origen';
$string['scheduleddeleteat'] = 'Borrado remoto previsto';
$string['error'] = 'Error';
$string['process_tasks'] = 'Procesar tareas de transferencia de cursos';
$string['categorynotfound'] = 'No se ha encontrado ninguna categoría con ese patrón de idnumber';


// Secciones del formulario.
$string['retention'] = 'Retención';



// Opciones de eliminación.

$string['emptyresponse'] = 'Respuesta vacía del Moodle remoto';
$string['invalidresponse'] = 'Respuesta no válida del Moodle remoto';
$string['remotecategoryerror'] = 'Fallo en la resolución de la categoría remota';



$string['originsyncfailed'] = 'No se ha podido verificar la sincronización con el sitio origen';

// Selector del sitio origen.
$string['originsite'] = 'Sitio origen';
$string['originsitenotset'] = 'No se ha configurado un sitio origen para esta tarea.';
$string['originsitenotfound'] = 'El sitio origen configurado ya no existe en local_coursetransfer.';

// Programación.
$string['lastruntime'] = 'Última ejecución';
$string['nextruntime'] = 'Próxima ejecución';

// Notificaciones del ciclo de vida (N1-N7).
$string['notif_launched_subject'] = 'Tarea "{$a->taskname}": restauración lanzada';
$string['notif_launched_body'] = 'La tarea "{$a->taskname}" ha lanzado la restauración de la categoría "{$a->categoryname}" desde {$a->host}. Corre en segundo plano y puede tardar horas; recibirás un aviso cuando termine. Síguela en {$a->url}';
$string['notif_launched_small'] = 'Restauración lanzada.';
$string['notif_completed_subject'] = 'Tarea "{$a->taskname}": restauración completada';
$string['notif_completed_body'] = 'La restauración de la categoría "{$a->categoryname}" ha terminado: todos sus cursos están ya en esta plataforma. Recuerda: se borrará de la plataforma origen el {$a->deletedate} salvo que alguien lo cancele en {$a->url}';
$string['notif_completed_small'] = 'Restauración completada.';
$string['notif_delwarn_subject'] = 'Aviso previo: "{$a->categoryname}" se borrará de la plataforma origen el {$a->deletedate}';
$string['notif_delwarn_body'] = 'El {$a->deletedate} la categoría "{$a->categoryname}" se borrará de {$a->host}. Aquí seguirá archivada. Si debe permanecer en el origen, cancela el borrado antes de esa fecha en {$a->url}';
$string['notif_delwarn_small'] = 'Borrado programado — cancelable.';
$string['notif_deldone_subject'] = '"{$a->categoryname}" se ha borrado de la plataforma origen';
$string['notif_deldone_body'] = 'La categoría "{$a->categoryname}" se ha borrado de {$a->host}, según lo programado por la tarea "{$a->taskname}". Sigue archivada en esta plataforma. Registro en {$a->url}';
$string['notif_deldone_small'] = 'Borrado en origen ejecutado.';
$string['notif_prunewarn_subject'] = 'Podado del archivo anunciado: "{$a->categoryname}"';
$string['notif_prunewarn_body'] = 'La categoría "{$a->categoryname}" supera los años que conserva el archivo y se podará el {$a->gracedate}. Para conservarla, exclúyela del podado antes de esa fecha en {$a->url}';
$string['notif_prunewarn_small'] = 'Candidata a podado anunciada — excluible.';
$string['notif_prunedone_subject'] = 'Podado del archivo ejecutado: "{$a->categoryname}"';
$string['notif_prunedone_body'] = 'La categoría "{$a->categoryname}" se ha eliminado del archivo, según lo configurado en la tarea "{$a->taskname}". Registro en {$a->url}';
$string['notif_prunedone_small'] = 'Podado ejecutado.';
$string['notif_error_subject'] = 'La tarea "{$a->taskname}" ha fallado';
$string['notif_error_body'] = 'La tarea "{$a->taskname}" ha fallado: {$a->detail} — Revisa la tarea o la plataforma origen y consulta el registro en {$a->url}';
$string['notif_error_small'] = 'Ejecución fallida.';

// Ajustes de administración.
$string['settings_link'] = 'Ajustes del gestor';
$string['setting_deletionwarningdays'] = 'Días de aviso antes de borrar en el origen';
$string['setting_deletionwarningdays_desc'] = 'Con cuántos días de antelación se envía el aviso previo cancelable (campana y correo) antes de un borrado programado en la plataforma origen.';
$string['setting_prunegracedays'] = 'Días de gracia antes de podar el archivo';
$string['setting_prunegracedays_desc'] = 'Días entre el anuncio de los candidatos al podado y su borrado, para dar tiempo a excluir lo que deba conservarse.';
$string['setting_deletionspaused'] = 'Pausar todos los borrados (freno de emergencia)';
$string['setting_deletionspaused_desc'] = 'Mientras esté activo, NO se ejecuta ningún borrado ni podado y sus fechas de vencimiento se van posponiendo solas, de modo que quitar la pausa nunca dispara un atasco de borrados. Las restauraciones no se ven afectadas.';
$string['panel_paused'] = 'Freno de emergencia activo: todos los borrados y podados están en pausa.';
$string['deletion_held_notcompleted'] = 'la restauración de «{$a}» no consta completada, así que no hay copia completa verificada en esta plataforma.';
$string['deletion_held_archivemissing'] = 'la copia archivada de «{$a}» ya no existe (o no tiene cursos) en esta plataforma.';

// Mensajes registrados en db/messages.php.
$string['messageprovider:execution_launched'] = 'Restauración lanzada (tarea disparada)';
$string['messageprovider:restore_completed'] = 'Restauración completada';
$string['messageprovider:deletion_warning'] = 'Aviso previo de borrado en la plataforma origen';
$string['messageprovider:deletion_done'] = 'Borrado en la plataforma origen ejecutado';
$string['messageprovider:prune_warning'] = 'Aviso previo de podado del archivo';
$string['messageprovider:prune_done'] = 'Podado del archivo ejecutado';
$string['messageprovider:execution_error'] = 'Tarea de transferencia fallida';
$string['messageprovider:deletion_held'] = 'Borrado retenido por seguridad';

// N8 — borrado retenido por un candado de seguridad (no es un fallo).
$string['notif_held_subject'] = 'Borrado retenido por seguridad: «{$a->categoryname}»';
$string['notif_held_body'] = 'No se ha borrado nada. La tarea "{$a->taskname}" tenía que borrar «{$a->categoryname}» de la plataforma origen, pero la comprobación de seguridad no pasó, así que el borrado se ha pospuesto 7 días. Motivo: {$a->reason} Revísalo y, si el borrado no debe producirse, cancélalo en {$a->url}';
$string['notif_held_small'] = 'Borrado retenido: copia sin verificar.';
$string['notif_cta'] = 'Abrir la pantalla de seguimiento';
$string['notif_signature'] = 'Aviso automático enviado por {$a->plugin} ({$a->component}) desde {$a->site} — {$a->host}';

// Panel de gestión.
$string['health_coursetransfer'] = 'CourseTransfer';
$string['health_coursetransfer_ok'] = 'Plugin operativo';
$string['health_coursetransfer_ko'] = 'Plugin no disponible — nada puede ejecutarse';
$string['health_cron'] = 'Cron de Moodle';
$string['health_cron_ok'] = 'Última pasada hace {$a}';
$string['health_cron_ko'] = 'Última pasada hace {$a} — revisa el cron';
$string['health_cron_never'] = 'Nunca ha corrido — nada se ejecutará';
$string['health_managertask'] = 'Tarea programada del gestor';
$string['health_managertask_ok'] = 'Habilitada · última pasada hace {$a}';
$string['health_managertask_disabled'] = 'Deshabilitada — ninguna tarea se disparará';
$string['health_managertask_stale'] = 'Habilitada · sin pasadas recientes';
$string['health_managertask_manage'] = 'Gestionar tareas programadas';
$string['relative_in'] = 'en {$a}';
$string['agenda_title'] = 'Próximamente';
$string['agenda_summary'] = '{$a->executions} ejecución(es) · {$a->deletions} borrado(s) pendiente(s)';
$string['agenda_empty'] = 'Sin actividad programada. Cuando una tarea esté activa, aquí verás su próxima ejecución y los borrados que se avecinan.';
$string['agenda_empty_short'] = 'Sin actividad programada';
$string['agenda_footer'] = 'Todo borrado se anuncia con antelación y se puede cancelar hasta el momento en que ocurre.';
$string['agenda_exec_label'] = 'Próxima ejecución';
$string['agenda_del_label'] = 'Borrado en el ORIGEN';
$string['agenda_prune_label'] = 'Podado del archivo';
$string['agenda_cancelled_label'] = 'Cancelado';
$string['agenda_exec_detail'] = 'Traerá la categoría «{$a->category}» de {$a->host}';
$string['agenda_del_detail'] = 'Se eliminará de {$a} la categoría completa. Aquí seguirá archivada.';
$string['agenda_prune_detail'] = 'Supera los años que conserva el archivo local. Se eliminará de esta plataforma salvo que la excluyas del podado.';
$string['agenda_category'] = 'Categoría «{$a}»';
$string['agenda_cancel'] = 'Cancelar borrado';
$string['agenda_exclude'] = 'Excluir del podado';
$string['tasks_title'] = 'Tareas configuradas';
$string['taskcount'] = '{$a} tarea(s)';
$string['filters'] = 'Filtros';
$string['filters_apply'] = 'Aplicar';
$string['filters_clear'] = 'Limpiar';
$string['tasks_empty_title'] = 'Aún no hay tareas de archivo';
$string['tasks_empty_body'] = 'Una tarea trae cada año una categoría completa de otra plataforma Moodle, la guarda en tu archivo y, pasado un tiempo, la borra del origen. Todo automático y siempre avisado. Crea la primera para empezar.';
$string['card_origin'] = 'Origen';
$string['card_brings'] = 'Qué trae';
$string['card_destination'] = 'Destino';
$string['card_next'] = 'Próxima ejecución';
$string['card_last'] = 'Última ejecución';
$string['card_paused'] = 'Pausada — no se ejecutará';
$string['card_next_on'] = 'Se ejecutará el {$a->date} · en {$a->relative}';
$string['card_next_unknown'] = 'No se pudo calcular la programación';
$string['card_type_auto'] = 'Migración anual';
$string['card_type_manual'] = 'Ejecución manual';
$string['card_viewdetail'] = 'Ver detalle';
$string['card_active'] = 'Activa';
$string['card_inactive'] = 'Inactiva';
$string['card_origin_ko'] = 'Su plataforma origen ({$a}) figura sin conexión. La próxima ejecución fallará hasta que se restablezca en CourseTransfer.';
$string['card_last_error'] = 'La última ejecución falló:';
$string['runnow'] = 'Ejecutar ahora';
$string['runnow_confirm_title'] = '¿Ejecutar «{$a}» ahora?';
$string['runnow_confirm_body'] = 'La migración se lanzará de inmediato, sin esperar a la programación. La restauración corre en segundo plano y puede tardar horas; síguela en la pantalla de seguimiento.';
$string['runnow_blocked_title'] = 'No se puede ejecutar ahora';
$string['runnow_blocked_body'] = '«{$a->name}» ya se ejecutó con éxito en este ciclo (el {$a->date}). Volver a lanzarla ahora duplicaría los cursos en el archivo. Espera al próximo ciclo o edita la tarea.';
$string['runnow_launched'] = 'Restauración lanzada. Corre en segundo plano — síguela en la pantalla de seguimiento.';
$string['runnow_failed'] = 'El lanzamiento falló: {$a}';
$string['cancel_modal_title'] = '¿Cancelar este borrado?';
$string['cancel_modal_body'] = '{$a} permanecerá donde está. Quedará registrado quién y cuándo lo canceló.';
$string['cancel_confirm'] = 'Sí, cancelar el borrado';
$string['exclude_modal_title'] = '¿Excluir del podado?';
$string['exclude_modal_body'] = '{$a} se conservará en el archivo. Quedará registrado quién y cuándo la excluyó.';
$string['exclude_confirm'] = 'Sí, conservarla';
$string['cancelled_by'] = 'Cancelado por {$a->name} · {$a->date}';

// Asistente de tarea.
$string['wizard_title'] = 'Nueva tarea de archivo anual';
$string['wizard_desc'] = 'Configura de dónde se trae una categoría cada año, dónde se guarda y qué se borrará después. Nada se borra sin avisarte antes.';
$string['wz_step1'] = 'Origen';
$string['wz_step1_hint'] = 'Plataforma y categoría';
$string['wz_step2'] = 'Destino';
$string['wz_step2_hint'] = 'Dónde se archiva';
$string['wz_step3'] = 'Programación';
$string['wz_step3_hint'] = 'Cuándo se ejecuta';
$string['wz_step4'] = 'Retenciones y avisos';
$string['wz_step4_hint'] = 'Qué se borra · a quién avisar';
$string['wz_step5'] = 'Revisar';
$string['wz_step5_hint'] = 'Confirmar y activar';
$string['wz_s1_title'] = '1. De dónde traemos el contenido';
$string['wz_s1_desc'] = 'Elige la plataforma y comprueba que la categoría existe antes de guardar.';
$string['wz_name_placeholder'] = 'p. ej. Archivo anual SJD';
$string['wz_name_help'] = 'Solo se usa para reconocerla en el panel y en los avisos.';
$string['wz_manage_sites'] = 'Gestionar plataformas en CourseTransfer';
$string['wz_last_test'] = 'Último test de conexión: hace {$a} · registrado en CourseTransfer';
$string['wz_no_test'] = 'Sin test de conexión registrado en CourseTransfer';
$string['wz_test_ok'] = 'Conexión OK';
$string['wz_test_ko'] = 'Sin conexión';
$string['wz_pattern_help'] = 'En la ejecución de este año se buscará el idnumber «{$a}».';
$string['wz_insert'] = 'Insertar:';
$string['wz_token_year'] = 'año actual ({$a})';
$string['wz_token_prevyear'] = 'año anterior ({$a})';
$string['wz_test_pattern'] = 'Probar patrón en el origen';
$string['wz_pat_loading'] = 'Consultando la plataforma origen…';
$string['wz_pat_ok'] = 'Casa exactamente una categoría';
$string['wz_pat_ok_hint'] = 'Esta es la categoría que la tarea traerá en cada ejecución.';
$string['wz_pat_none'] = 'Ninguna categoría casa con este patrón';
$string['wz_pat_none_hint'] = 'Revisa el idnumber en el origen o ajusta el patrón. Si guardas así, la ejecución fallará.';
$string['wz_pat_many'] = 'El patrón casa {$a} categorías';
$string['wz_pat_many_hint'] = 'La tarea necesita un patrón inequívoco: afínalo hasta que case exactamente una.';
$string['wz_pat_down'] = 'El origen no responde';
$string['wz_pat_down_hint'] = 'Puede que el token haya caducado o que el sitio esté caído. Revisa la plataforma en CourseTransfer y vuelve a probar. Detalle: {$a}';
$string['wz_pat_invalid'] = 'El patrón no es una expresión válida';
$string['wz_s2_title'] = '2. Dónde se guarda en esta plataforma';
$string['wz_s2_desc'] = 'La categoría traída se colocará bajo la categoría de archivo que elijas.';
$string['wz_dest_placeholder'] = 'Busca una categoría del archivo…';
$string['wz_dest_help'] = 'Buscador con resultados del servidor: escribe para filtrar, no se vuelca todo el catálogo.';
$string['wz_clear_selection'] = 'Borrar selección';
$string['wz_dest_preview'] = 'Quedará así';
$string['wz_dest_preview_line'] = 'Categoría «{$a}» (traída cada año)';
$string['wz_searching'] = 'Buscando en el servidor…';
$string['wz_noresults'] = 'Sin resultados para «{$a}». Prueba con otro término.';
$string['wz_copy_what'] = 'Qué copiamos';
$string['wz_copy_users'] = 'Cursos y datos de usuario';
$string['wz_copy_users_desc'] = 'Matrículas, notas y entregas. Archivo completo, pesa más.';
$string['wz_copy_courses'] = 'Solo los cursos';
$string['wz_copy_courses_desc'] = 'Contenidos y estructura, sin personas ni datos personales.';
$string['wz_copy_users_note'] = 'Copiar datos de usuario implica tratar datos personales en esta plataforma. Comprueba que encaja con vuestra política de retención.';
$string['wz_s3_title'] = '3. Cuándo se ejecuta';
$string['wz_s3_desc'] = 'Dilo en lenguaje normal. Debajo verás las fechas reales.';
$string['wz_sched_semantic'] = 'Fecha y hora';
$string['wz_sched_cron'] = 'Avanzado (cron)';
$string['wz_once_a_year'] = 'Una vez al año, el';
$string['wz_of'] = 'de';
$string['wz_at'] = 'a las';
$string['wz_day'] = 'Día';
$string['wz_month'] = 'Mes';
$string['wz_hour'] = 'Hora';
$string['wz_server_time'] = 'Hora del servidor.';
$string['wz_cron_help'] = 'minuto · hora · día del mes · mes · día de la semana. Para casos que no caben en «una vez al año».';
$string['wz_next_runs'] = 'Próximas ejecuciones';
$string['wz_summer_warning'] = 'Cae en verano. El curso puede no estar cerrado todavía: comprueba que a esa fecha ya haya contenido definitivo que traer.';
$string['wz_s4_eyebrow'] = 'Este paso configura borrados';
$string['wz_s4_title'] = '4. Retenciones y avisos';
$string['wz_s4_desc'] = 'Qué se borra y cuándo, y a quién avisamos. Todo borrado se anuncia antes y se puede cancelar.';
$string['wz_ret_origin'] = 'Borrado en la plataforma origen';
$string['wz_ret_origin_desc'] = 'Pasados los días que indiques, la categoría SE ELIMINA de {$a}. Aquí seguirá archivada.';
$string['wz_ret_origin_box'] = 'Con la primera ejecución del {$a->first}, el borrado en origen caería el {$a->deletion}. Te avisaremos el {$a->warning} ({$a->days} días antes) con un enlace para cancelarlo.';
$string['wz_ret_short'] = 'Es poco margen: restaurar puede tardar horas y alguien tiene que revisar el archivo antes de que el original desaparezca. Recomendamos 30 días o más.';
$string['wz_ret_archive'] = 'Podado del archivo de esta plataforma';
$string['wz_ret_archive_desc'] = 'Cuántos años de archivo conservamos aquí. Lo más antiguo se anuncia como candidato y, si nadie lo excluye, se elimina.';
$string['wz_ret_archive_box'] = 'El archivo conservaría los últimos {$a->years} años. Lo de {$a->cutoff} o anterior pasaría a candidato a podado, con {$a->grace} días de gracia para excluirlo.';
$string['wz_notices'] = 'Avisos de esta tarea';
$string['wz_notices_desc'] = 'Quién recibe las notificaciones del ciclo de vida, además del creador de la tarea.';
$string['wz_recipients'] = 'Destinatarios';
$string['wz_recipients_placeholder'] = 'Añade personas por nombre o correo…';
$string['wz_creator_fixed'] = '{$a} (creador) · fijo';
$string['wz_level'] = 'Nivel de aviso';
$string['wz_level_full'] = 'Completo';
$string['wz_level_full_desc'] = 'Todo el ciclo de vida: lanzada, completada, avisos, borrados y podado.';
$string['wz_level_essential'] = 'Esencial';
$string['wz_level_essential_desc'] = 'Solo lo crítico: avisos previos a borrados, borrados y errores.';
$string['wz_level_note'] = 'Los avisos críticos —los previos a un borrado y los errores— se envían SIEMPRE, sea cual sea el nivel.';
$string['wz_s5_title'] = '5. Revisa antes de activarla';
$string['wz_s5_desc'] = 'Esto es lo que va a pasar, con fechas reales, si la guardas hoy.';
$string['wz_rev_origin'] = 'Categoría con idnumber «{$a->idnumber}» · patrón {$a->pattern}';
$string['wz_rev_first'] = 'Primera vez: {$a}';
$string['wz_rev_ret'] = '{$a->days} días en origen · {$a->years} años de archivo';
$string['wz_timeline'] = 'Línea de tiempo del ciclo de vida';
$string['wz_tl1_title'] = 'Se dispara la tarea';
$string['wz_tl1_desc'] = 'El proceso arranca solo. No hace falta que nadie esté delante.';
$string['wz_tl2_title'] = 'Se busca la categoría en el origen';
$string['wz_tl2_desc'] = 'Se busca el idnumber «{$a->category}» en {$a->host}. Si no casa o no conecta, la ejecución queda en error y te avisamos con la causa.';
$string['wz_tl3_title'] = 'Restauración lanzada';
$string['wz_tl3_desc'] = '«Lanzada» no es «terminada»: el progreso curso a curso se sigue en el registro de CourseTransfer.';
$string['wz_tl4_title'] = 'La categoría queda recolocada';
$string['wz_tl4_desc'] = 'Aparece bajo la categoría de archivo elegida, aún restaurando cursos.';
$string['wz_tl5_title'] = 'Restauración completada de verdad';
$string['wz_tl5_desc'] = 'Todos los cursos restaurados. Aquí empieza la cuenta atrás de {$a->days} días.';
$string['wz_tl6_title'] = 'Aviso previo al borrado en origen';
$string['wz_tl6_desc'] = 'Campana y correo, {$a->warningdays} días antes, con enlace directo para cancelar el borrado.';
$string['wz_tl7_title'] = 'Se borra de la plataforma origen';
$string['wz_tl7_desc'] = 'Si nadie lo cancela, «{$a->category}» se elimina de {$a->host}. Aquí sigue archivada.';
$string['wz_tl8_title'] = 'Candidatos a podado anunciados';
$string['wz_tl8_desc'] = 'Lo de {$a->cutoff} o anterior se anuncia con {$a->grace} días de gracia para excluir lo que quieras conservar.';
$string['wz_tl9_title'] = 'Podado del archivo';
$string['wz_tl9_desc'] = 'Se eliminan del archivo los años ya no cubiertos.';
$string['wz_tl_sameday'] = 'mismo día';
$string['wz_tl_hours'] = 'puede tardar horas';
$string['wz_tl_aftergrace'] = 'tras la gracia';
$string['wz_confirm_title'] = 'Esta tarea borrará contenido en otra plataforma';
$string['wz_confirm_summary'] = 'El {$a->deletion} se eliminará la categoría «{$a->category}» de {$a->host}. Recibirás un aviso cancelable el {$a->warning}.';
$string['wz_confirm_check'] = 'Entiendo que esta tarea borrará automáticamente contenido de la plataforma origen y del archivo de esta plataforma.';
$string['wz_back'] = 'Atrás';
$string['wz_continue'] = 'Continuar';
$string['wz_save_new'] = 'Guardar y activar la tarea';
$string['wz_save_edit'] = 'Guardar cambios';
$string['wz_done_title'] = 'Tarea guardada y activa';
$string['wz_done_desc'] = 'Se ejecutará por primera vez el {$a}. Podrás cancelar el borrado en origen desde el panel mientras no haya ocurrido.';
$string['wz_done_back'] = 'Ir al panel';

// Pantalla de seguimiento.
$string['exec_title'] = 'Ejecuciones y borrados';
$string['exec_desc'] = 'Qué se está trayendo ahora, qué se lanzará después y qué se va a borrar. Cada ejecución cuenta en qué punto del ciclo está.';
$string['exec_scope'] = 'Tarea: {$a}';
$string['exec_scope_clear'] = 'Quitar el filtro de tarea';
$string['exec_tab_live'] = 'En curso';
$string['exec_tab_deletions'] = 'Próximos borrados';
$string['exec_tab_history'] = 'Registro';
$string['exec_live_title'] = 'Ejecuciones en curso';
$string['exec_live_empty'] = 'No hay ninguna ejecución en marcha ahora mismo.';
$string['exec_live_line'] = 'Trayendo la categoría «{$a}»';
$string['exec_live_since'] = 'empezó hace {$a}';
$string['exec_fine_detail'] = 'Ver detalle fino en CourseTransfer';
$string['exec_phase_launched'] = 'Lanzada';
$string['exec_phase_restoring'] = 'Restaurando';
$string['exec_phase_completed'] = 'Completada';
$string['exec_stalled'] = 'Esta fase lleva más de lo normal. Causa probable: el cron de alguna de las dos plataformas podría estar parado.';
$string['exec_stalled_checks'] = 'Revisa los checks de salud';
$string['exec_refreshed'] = 'Actualizado hace {$a}s';
$string['exec_upcoming_title'] = 'Próximas ejecuciones programadas';
$string['exec_upcoming_empty'] = 'No hay próximas ejecuciones programadas.';
$string['exec_upcoming_line'] = 'Traerá la categoría «{$a}»';
$string['exec_deletions_intro'] = 'Todo lo que este plugin va a borrar, en un solo sitio. Puedes cancelar cualquier borrado mientras siga pendiente: la categoría permanecerá donde está.';
$string['exec_deletions_empty'] = 'No hay ningún borrado pendiente. Nada se eliminará de forma automática por ahora.';
$string['exec_urgent'] = 'urgente';
$string['exec_del_date'] = 'Fecha programada';
$string['exec_del_origin'] = 'Originado por';
$string['exec_filter_task'] = 'Tarea';
$string['exec_history_empty'] = 'No hay ejecuciones registradas con estos filtros.';
$string['exec_col_date'] = 'Fecha';
$string['exec_col_what'] = 'Tarea y categoría';
$string['exec_col_type'] = 'Tipo';
$string['exec_col_request'] = 'Petición';
$string['exec_type_restore'] = 'Restaurar categoría';
$string['exec_type_prune'] = 'Podado del archivo';
$string['exec_status_cancelled'] = 'Borrado cancelado';
$string['exec_status_deleted'] = 'Borrada';
$string['exec_view_error'] = 'Ver error';
$string['exec_error_title'] = 'La ejecución falló';
$string['exec_showing'] = 'Mostrando {$a->shown} de {$a->total} entradas';
$string['exec_prev'] = 'Anterior';
$string['exec_next'] = 'Siguiente';

// Ciclo de borrados / podado.
$string['deletionnotcancellable'] = 'Este borrado no se puede cancelar: ya no está pendiente.';
$string['prunenotexcludable'] = 'Esta categoría no se puede excluir: ya no es candidata al podado.';

// Privacy API.
$string['privacy:metadata:local_ctm_tasks'] = 'Tareas de transferencia configuradas en este sitio. Se guardan referencias de usuario para saber quién creó cada tarea y quién recibe sus avisos.';
$string['privacy:metadata:local_ctm_tasks:usercreated'] = 'Usuario que creó la tarea. Recibe los avisos de su ciclo de vida.';
$string['privacy:metadata:local_ctm_tasks:notifyrecipients'] = 'Usuarios adicionales que reciben los avisos de esta tarea.';
$string['privacy:metadata:local_ctm_executions'] = 'Ejecuciones de las tareas de transferencia. Se guarda una referencia de usuario cuando alguien cancela un borrado remoto programado.';
$string['privacy:metadata:local_ctm_executions:deletecancelledby'] = 'Usuario que canceló el borrado programado en la plataforma origen.';
$string['privacy:metadata:local_ctm_executions:deletecancelledat'] = 'Cuándo se canceló el borrado programado.';
$string['privacy:metadata:local_ctm_prune'] = 'Candidatos al podado del archivo. Se guarda una referencia de usuario cuando alguien excluye una categoría del podado.';
$string['privacy:metadata:local_ctm_prune:excludedby'] = 'Usuario que excluyó la categoría del podado.';
$string['privacy:metadata:local_ctm_prune:excludedat'] = 'Cuándo se excluyó la categoría del podado.';