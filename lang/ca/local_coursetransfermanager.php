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
 * Cadenes d'idioma per a local_coursetransfermanager (català).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Gestor de transferències de cursos';
$string['coursetransfermanager:managetasks'] = 'Gestionar les transferències programades de cursos';
$string['managetasks'] = 'Transferències programades';
$string['managetasks_desc'] = 'Arxiu anual automàtic de categories de cursos entre plataformes Moodle. Aquí veus què s\'executarà i què s\'esborrarà properament.';
$string['id'] = 'ID';
$string['type'] = 'Tipus';
$string['status'] = 'Estat';
$string['fromdate'] = 'Des de';
$string['todate'] = 'Fins a';

$string['status_error'] = 'Error';
$string['status_completed'] = 'Completada';
$string['status_success'] = 'Èxit';


$string['createtask'] = 'Tasca nova';
$string['edittask'] = 'Editar tasca';

$string['taskname'] = 'Nom';
$string['categorypattern'] = 'Patró de categoria';
$string['targetcategory'] = 'Categoria de destinació';
$string['cronexpression'] = 'Expressió cron';
$string['retentiondays'] = 'Dies de retenció';
$string['destinationkeepyears'] = 'Anys a mantenir a la destinació';
$string['restoreuserdata'] = 'Restaurar usuaris';
$string['enabled'] = 'Activa';

$string['invalidcron'] = 'L\'expressió cron ha de tenir 5 parts';

$string['actions'] = 'Accions';
$string['executions'] = 'Execucions';

$string['deletetask'] = 'Esborrar tasca';
$string['taskdeleted'] = 'Tasca esborrada correctament';
$string['confirmdeletetask'] = 'Esteu segur que voleu esborrar la tasca "{$a}"? També s\'esborrarà el seu historial d\'execucions.';

$string['requestid'] = 'Request ID';
$string['origincategoryid'] = 'ID categoria d\'origen';
$string['origincategoryname'] = 'Categoria d\'origen';
$string['scheduleddeleteat'] = 'Esborrament remot previst';
$string['error'] = 'Error';
$string['process_tasks'] = 'Processar tasques de transferència de cursos';
$string['categorynotfound'] = 'No s\'ha trobat cap categoria amb aquest patró d\'idnumber';


// Seccions del formulari.
$string['retention'] = 'Retenció';



// Opcions d'eliminació.

$string['emptyresponse'] = 'Resposta buida del Moodle remot';
$string['invalidresponse'] = 'Resposta no vàlida del Moodle remot';
$string['remotecategoryerror'] = 'Ha fallat la resolució de la categoria remota';



$string['originsyncfailed'] = 'No s\'ha pogut verificar la sincronització amb el lloc d\'origen';

// Selector del lloc d'origen.
$string['originsite'] = 'Lloc d\'origen';
$string['originsitenotset'] = 'No s\'ha configurat cap lloc d\'origen per a aquesta tasca.';
$string['originsitenotfound'] = 'El lloc d\'origen configurat ja no existeix a local_coursetransfer.';

// Programació.
$string['lastruntime'] = 'Última execució';
$string['nextruntime'] = 'Pròxima execució';

// Notificacions del cicle de vida (N1-N7).
$string['notif_launched_subject'] = 'Tasca "{$a->taskname}": restauració llançada';
$string['notif_launched_body'] = 'La tasca "{$a->taskname}" ha llançat la restauració de la categoria "{$a->categoryname}" des de {$a->host}. Corre en segon pla i pot trigar hores; rebràs un avís quan acabi. Segueix-la a {$a->url}';
$string['notif_launched_small'] = 'Restauració llançada.';
$string['notif_completed_subject'] = 'Tasca "{$a->taskname}": restauració completada';
$string['notif_completed_body'] = 'La restauració de la categoria "{$a->categoryname}" ha acabat: tots els seus cursos ja són en aquesta plataforma. Recorda: s\'esborrarà de la plataforma origen el {$a->deletedate} llevat que algú ho cancel·li a {$a->url}';
$string['notif_completed_small'] = 'Restauració completada.';
$string['notif_delwarn_subject'] = 'Avís previ: "{$a->categoryname}" s\'esborrarà de la plataforma origen el {$a->deletedate}';
$string['notif_delwarn_body'] = 'El {$a->deletedate} la categoria "{$a->categoryname}" s\'esborrarà de {$a->host}. Aquí seguirà arxivada. Si ha de romandre a l\'origen, cancel·la l\'esborrament abans d\'aquesta data a {$a->url}';
$string['notif_delwarn_small'] = 'Esborrament programat — cancel·lable.';
$string['notif_deldone_subject'] = '"{$a->categoryname}" s\'ha esborrat de la plataforma origen';
$string['notif_deldone_body'] = 'La categoria "{$a->categoryname}" s\'ha esborrat de {$a->host}, segons el que va programar la tasca "{$a->taskname}". Segueix arxivada en aquesta plataforma. Registre a {$a->url}';
$string['notif_deldone_small'] = 'Esborrament a l\'origen executat.';
$string['notif_prunewarn_subject'] = 'Poda de l\'arxiu anunciada: "{$a->categoryname}"';
$string['notif_prunewarn_body'] = 'La categoria "{$a->categoryname}" supera els anys que conserva l\'arxiu i es podarà el {$a->gracedate}. Per conservar-la, exclou-la de la poda abans d\'aquesta data a {$a->url}';
$string['notif_prunewarn_small'] = 'Candidata a poda anunciada — excloïble.';
$string['notif_prunedone_subject'] = 'Poda de l\'arxiu executada: "{$a->categoryname}"';
$string['notif_prunedone_body'] = 'La categoria "{$a->categoryname}" s\'ha eliminat de l\'arxiu, segons la configuració de la tasca "{$a->taskname}". Registre a {$a->url}';
$string['notif_prunedone_small'] = 'Poda executada.';
$string['notif_error_subject'] = 'La tasca "{$a->taskname}" ha fallat';
$string['notif_error_body'] = 'La tasca "{$a->taskname}" ha fallat: {$a->detail} — Revisa la tasca o la plataforma origen i consulta el registre a {$a->url}';
$string['notif_error_small'] = 'Execució fallida.';

// Paràmetres d'administració.
$string['settings_link'] = 'Paràmetres del gestor';
$string['setting_deletionwarningdays'] = 'Dies d\'avís abans d\'esborrar a l\'origen';
$string['setting_deletionwarningdays_desc'] = 'Amb quants dies d\'antelació s\'envia l\'avís previ cancel·lable (campana i correu) abans d\'un esborrament programat a la plataforma origen.';
$string['setting_prunegracedays'] = 'Dies de gràcia abans de podar l\'arxiu';
$string['setting_prunegracedays_desc'] = 'Dies entre l\'anunci dels candidats a la poda i el seu esborrament, per donar temps a excloure el que s\'hagi de conservar.';
$string['setting_deletionspaused'] = 'Pausar tots els esborraments (fre d\'emergència)';
$string['setting_deletionspaused_desc'] = 'Mentre estigui actiu, NO s\'executa cap esborrament ni poda i les seves dates de venciment es van posposant soles, de manera que treure la pausa mai no dispara un embús d\'esborraments. Les restauracions no es veuen afectades.';
$string['panel_paused'] = 'Fre d\'emergència actiu: tots els esborraments i podes estan en pausa.';
$string['deletion_held_notcompleted'] = 'la restauració de «{$a}» no consta completada, així que no hi ha còpia completa verificada en aquesta plataforma.';
$string['deletion_held_archivemissing'] = 'la còpia arxivada de «{$a}» ja no existeix (o no té cursos) en aquesta plataforma.';

// Missatges registrats a db/messages.php.
$string['messageprovider:execution_launched'] = 'Restauració llançada (tasca disparada)';
$string['messageprovider:restore_completed'] = 'Restauració completada';
$string['messageprovider:deletion_warning'] = 'Avís previ d\'esborrament a la plataforma origen';
$string['messageprovider:deletion_done'] = 'Esborrament a la plataforma origen executat';
$string['messageprovider:prune_warning'] = 'Avís previ de poda de l\'arxiu';
$string['messageprovider:prune_done'] = 'Poda de l\'arxiu executada';
$string['messageprovider:execution_error'] = 'Tasca de transferència fallida';
$string['messageprovider:deletion_held'] = 'Esborrament retingut per seguretat';

// N8 — esborrament retingut per un pany de seguretat (no és una fallada).
$string['notif_held_subject'] = 'Esborrament retingut per seguretat: «{$a->categoryname}»';
$string['notif_held_body'] = 'No s\'ha esborrat res. La tasca "{$a->taskname}" havia d\'esborrar «{$a->categoryname}» de la plataforma origen, però la comprovació de seguretat no va passar, així que l\'esborrament s\'ha posposat 7 dies. Motiu: {$a->reason} Revisa-ho i, si l\'esborrament no s\'ha de produir, cancel·la\'l a {$a->url}';
$string['notif_held_small'] = 'Esborrament retingut: còpia sense verificar.';
$string['notif_cta'] = 'Obrir la pantalla de seguiment';
$string['notif_signature'] = 'Avís automàtic enviat per {$a->plugin} ({$a->component}) des de {$a->site} — {$a->host}';

// Panell de gestió.
$string['health_coursetransfer'] = 'CourseTransfer';
$string['health_coursetransfer_ok'] = 'Connector operatiu';
$string['health_coursetransfer_ko'] = 'Connector no disponible — res no es pot executar';
$string['health_cron'] = 'Cron de Moodle';
$string['health_cron_ok'] = 'Última passada fa {$a}';
$string['health_cron_ko'] = 'Última passada fa {$a} — revisa el cron';
$string['health_cron_never'] = 'No ha corregut mai — res no s\'executarà';
$string['health_managertask'] = 'Tasca programada del gestor';
$string['health_managertask_ok'] = 'Habilitada · última passada fa {$a}';
$string['health_managertask_disabled'] = 'Deshabilitada — cap tasca no es dispararà';
$string['health_managertask_stale'] = 'Habilitada · sense passades recents';
$string['health_managertask_manage'] = 'Gestionar tasques programades';
$string['relative_in'] = 'd\'aquí a {$a}';
$string['agenda_title'] = 'Properament';
$string['agenda_summary'] = '{$a->executions} execució(ns) · {$a->deletions} esborrament(s) pendent(s)';
$string['agenda_empty'] = 'Sense activitat programada. Quan una tasca estigui activa, aquí veuràs la seva propera execució i els esborraments que s\'acosten.';
$string['agenda_empty_short'] = 'Sense activitat programada';
$string['agenda_footer'] = 'Tot esborrament s\'anuncia amb antelació i es pot cancel·lar fins al moment en què passa.';
$string['agenda_exec_label'] = 'Propera execució';
$string['agenda_del_label'] = 'Esborrament a l\'ORIGEN';
$string['agenda_prune_label'] = 'Poda de l\'arxiu';
$string['agenda_cancelled_label'] = 'Cancel·lat';
$string['agenda_exec_detail'] = 'Portarà la categoria «{$a->category}» de {$a->host}';
$string['agenda_del_detail'] = 'S\'eliminarà de {$a} la categoria completa. Aquí seguirà arxivada.';
$string['agenda_prune_detail'] = 'Supera els anys que conserva l\'arxiu local. S\'eliminarà d\'aquesta plataforma llevat que l\'excloguis de la poda.';
$string['agenda_category'] = 'Categoria «{$a}»';
$string['agenda_cancel'] = 'Cancel·lar esborrament';
$string['agenda_exclude'] = 'Excloure de la poda';
$string['tasks_title'] = 'Tasques configurades';
$string['taskcount'] = '{$a} tasca(ques)';
$string['filters'] = 'Filtres';
$string['filters_apply'] = 'Aplicar';
$string['filters_clear'] = 'Netejar';
$string['tasks_empty_title'] = 'Encara no hi ha tasques d\'arxiu';
$string['tasks_empty_body'] = 'Una tasca porta cada any una categoria completa d\'una altra plataforma Moodle, la guarda al teu arxiu i, passat un temps, l\'esborra de l\'origen. Tot automàtic i sempre avisat. Crea la primera per començar.';
$string['card_origin'] = 'Origen';
$string['card_brings'] = 'Què porta';
$string['card_destination'] = 'Destinació';
$string['card_next'] = 'Propera execució';
$string['card_last'] = 'Última execució';
$string['card_paused'] = 'Pausada — no s\'executarà';
$string['card_next_on'] = 'S\'executarà el {$a->date} · d\'aquí a {$a->relative}';
$string['card_next_unknown'] = 'No s\'ha pogut calcular la programació';
$string['card_type_auto'] = 'Migració anual';
$string['card_type_manual'] = 'Execució manual';
$string['card_viewdetail'] = 'Veure detall';
$string['card_active'] = 'Activa';
$string['card_inactive'] = 'Inactiva';
$string['card_origin_ko'] = 'La seva plataforma origen ({$a}) figura sense connexió. La propera execució fallarà fins que es restableixi a CourseTransfer.';
$string['card_last_error'] = 'L\'última execució va fallar:';
$string['runnow'] = 'Executar ara';
$string['runnow_confirm_title'] = 'Executar «{$a}» ara?';
$string['runnow_confirm_body'] = 'La migració es llançarà immediatament, sense esperar la programació. La restauració corre en segon pla i pot trigar hores; segueix-la a la pantalla de seguiment.';
$string['runnow_blocked_title'] = 'No es pot executar ara';
$string['runnow_blocked_body'] = '«{$a->name}» ja es va executar amb èxit en aquest cicle (el {$a->date}). Tornar a llançar-la ara duplicaria els cursos a l\'arxiu. Espera al proper cicle o edita la tasca.';
$string['runnow_launched'] = 'Restauració llançada. Corre en segon pla — segueix-la a la pantalla de seguiment.';
$string['runnow_failed'] = 'El llançament va fallar: {$a}';
$string['cancel_modal_title'] = 'Cancel·lar aquest esborrament?';
$string['cancel_modal_body'] = '{$a} romandrà on és. Quedarà registrat qui i quan ho va cancel·lar.';
$string['cancel_confirm'] = 'Sí, cancel·lar l\'esborrament';
$string['exclude_modal_title'] = 'Excloure de la poda?';
$string['exclude_modal_body'] = '{$a} es conservarà a l\'arxiu. Quedarà registrat qui i quan la va excloure.';
$string['exclude_confirm'] = 'Sí, conservar-la';
$string['cancelled_by'] = 'Cancel·lat per {$a->name} · {$a->date}';

// Assistent de tasca.
$string['wizard_title'] = 'Tasca nova d\'arxiu anual';
$string['wizard_desc'] = 'Configura d\'on es porta una categoria cada any, on es guarda i què s\'esborrarà després. Res no s\'esborra sense avisar-te abans.';
$string['wz_step1'] = 'Origen';
$string['wz_step1_hint'] = 'Plataforma i categoria';
$string['wz_step2'] = 'Destinació';
$string['wz_step2_hint'] = 'On s\'arxiva';
$string['wz_step3'] = 'Programació';
$string['wz_step3_hint'] = 'Quan s\'executa';
$string['wz_step4'] = 'Retencions i avisos';
$string['wz_step4_hint'] = 'Què s\'esborra · a qui avisar';
$string['wz_step5'] = 'Revisar';
$string['wz_step5_hint'] = 'Confirmar i activar';
$string['wz_s1_title'] = '1. D\'on portem el contingut';
$string['wz_s1_desc'] = 'Tria la plataforma i comprova que la categoria existeix abans de desar.';
$string['wz_name_placeholder'] = 'p. ex. Arxiu anual SJD';
$string['wz_name_help'] = 'Només es fa servir per reconèixer-la al panell i als avisos.';
$string['wz_manage_sites'] = 'Gestionar plataformes a CourseTransfer';
$string['wz_last_test'] = 'Últim test de connexió: fa {$a} · registrat a CourseTransfer';
$string['wz_no_test'] = 'Sense test de connexió registrat a CourseTransfer';
$string['wz_test_ok'] = 'Connexió OK';
$string['wz_test_ko'] = 'Sense connexió';
$string['wz_pattern_help'] = 'A l\'execució d\'enguany es buscarà l\'idnumber «{$a}».';
$string['wz_insert'] = 'Inserir:';
$string['wz_token_year'] = 'any actual ({$a})';
$string['wz_token_prevyear'] = 'any anterior ({$a})';
$string['wz_test_pattern'] = 'Provar patró a l\'origen';
$string['wz_pat_loading'] = 'Consultant la plataforma origen…';
$string['wz_pat_ok'] = 'Casa exactament una categoria';
$string['wz_pat_ok_hint'] = 'Aquesta és la categoria que la tasca portarà a cada execució.';
$string['wz_pat_none'] = 'Cap categoria casa amb aquest patró';
$string['wz_pat_none_hint'] = 'Revisa l\'idnumber a l\'origen o ajusta el patró. Si deses així, l\'execució fallarà.';
$string['wz_pat_many'] = 'El patró casa {$a} categories';
$string['wz_pat_many_hint'] = 'La tasca necessita un patró inequívoc: afina\'l fins que casi exactament una.';
$string['wz_pat_down'] = 'L\'origen no respon';
$string['wz_pat_down_hint'] = 'Pot ser que el token hagi caducat o que el lloc estigui caigut. Revisa la plataforma a CourseTransfer i torna a provar. Detall: {$a}';
$string['wz_pat_invalid'] = 'El patró no és una expressió vàlida';
$string['wz_s2_title'] = '2. On es guarda en aquesta plataforma';
$string['wz_s2_desc'] = 'La categoria portada es col·locarà sota la categoria d\'arxiu que triïs.';
$string['wz_dest_placeholder'] = 'Cerca una categoria de l\'arxiu…';
$string['wz_dest_help'] = 'Cercador amb resultats del servidor: escriu per filtrar, no es bolca tot el catàleg.';
$string['wz_clear_selection'] = 'Esborrar selecció';
$string['wz_dest_preview'] = 'Quedarà així';
$string['wz_dest_preview_line'] = 'Categoria «{$a}» (portada cada any)';
$string['wz_searching'] = 'Cercant al servidor…';
$string['wz_noresults'] = 'Sense resultats per a «{$a}». Prova un altre terme.';
$string['wz_copy_what'] = 'Què copiem';
$string['wz_copy_users'] = 'Cursos i dades d\'usuari';
$string['wz_copy_users_desc'] = 'Matrícules, notes i lliuraments. Arxiu complet, pesa més.';
$string['wz_copy_courses'] = 'Només els cursos';
$string['wz_copy_courses_desc'] = 'Continguts i estructura, sense persones ni dades personals.';
$string['wz_copy_users_note'] = 'Copiar dades d\'usuari implica tractar dades personals en aquesta plataforma. Comprova que encaixa amb la vostra política de retenció.';
$string['wz_s3_title'] = '3. Quan s\'executa';
$string['wz_s3_desc'] = 'Digues-ho en llenguatge normal. A sota veuràs les dates reals.';
$string['wz_sched_semantic'] = 'Data i hora';
$string['wz_sched_cron'] = 'Avançat (cron)';
$string['wz_once_a_year'] = 'Un cop l\'any, el';
$string['wz_of'] = 'de';
$string['wz_at'] = 'a les';
$string['wz_day'] = 'Dia';
$string['wz_month'] = 'Mes';
$string['wz_hour'] = 'Hora';
$string['wz_server_time'] = 'Hora del servidor.';
$string['wz_cron_help'] = 'minut · hora · dia del mes · mes · dia de la setmana. Per a casos que no caben a «un cop l\'any».';
$string['wz_next_runs'] = 'Properes execucions';
$string['wz_summer_warning'] = 'Cau a l\'estiu. El curs pot no estar tancat encara: comprova que en aquesta data ja hi hagi contingut definitiu per portar.';
$string['wz_s4_eyebrow'] = 'Aquest pas configura esborraments';
$string['wz_s4_title'] = '4. Retencions i avisos';
$string['wz_s4_desc'] = 'Què s\'esborra i quan, i a qui avisem. Tot esborrament s\'anuncia abans i es pot cancel·lar.';
$string['wz_ret_origin'] = 'Esborrament a la plataforma origen';
$string['wz_ret_origin_desc'] = 'Passats els dies que indiquis, la categoria S\'ELIMINA de {$a}. Aquí seguirà arxivada.';
$string['wz_ret_origin_box'] = 'Amb la primera execució del {$a->first}, l\'esborrament a l\'origen cauria el {$a->deletion}. T\'avisarem el {$a->warning} ({$a->days} dies abans) amb un enllaç per cancel·lar-lo.';
$string['wz_ret_short'] = 'És poc marge: restaurar pot trigar hores i algú ha de revisar l\'arxiu abans que l\'original desaparegui. Recomanem 30 dies o més.';
$string['wz_ret_archive'] = 'Poda de l\'arxiu d\'aquesta plataforma';
$string['wz_ret_archive_desc'] = 'Quants anys d\'arxiu conservem aquí. El més antic s\'anuncia com a candidat i, si ningú no l\'exclou, s\'elimina.';
$string['wz_ret_archive_box'] = 'L\'arxiu conservaria els últims {$a->years} anys. El de {$a->cutoff} o anterior passaria a candidat a poda, amb {$a->grace} dies de gràcia per excloure\'l.';
$string['wz_notices'] = 'Avisos d\'aquesta tasca';
$string['wz_notices_desc'] = 'Qui rep les notificacions del cicle de vida, a més del creador de la tasca.';
$string['wz_recipients'] = 'Destinataris';
$string['wz_recipients_placeholder'] = 'Afegeix persones per nom o correu…';
$string['wz_creator_fixed'] = '{$a} (creador) · fix';
$string['wz_level'] = 'Nivell d\'avís';
$string['wz_level_full'] = 'Complet';
$string['wz_level_full_desc'] = 'Tot el cicle de vida: llançada, completada, avisos, esborraments i poda.';
$string['wz_level_essential'] = 'Essencial';
$string['wz_level_essential_desc'] = 'Només el crític: avisos previs a esborraments, esborraments i errors.';
$string['wz_level_note'] = 'Els avisos crítics —els previs a un esborrament i els errors— s\'envien SEMPRE, sigui quin sigui el nivell.';
$string['wz_s5_title'] = '5. Revisa abans d\'activar-la';
$string['wz_s5_desc'] = 'Això és el que passarà, amb dates reals, si la deses avui.';
$string['wz_rev_origin'] = 'Categoria amb idnumber «{$a->idnumber}» · patró {$a->pattern}';
$string['wz_rev_first'] = 'Primera vegada: {$a}';
$string['wz_rev_ret'] = '{$a->days} dies a l\'origen · {$a->years} anys d\'arxiu';
$string['wz_timeline'] = 'Línia de temps del cicle de vida';
$string['wz_tl1_title'] = 'Es dispara la tasca';
$string['wz_tl1_desc'] = 'El procés arrenca sol. No cal que ningú hi sigui davant.';
$string['wz_tl2_title'] = 'Es busca la categoria a l\'origen';
$string['wz_tl2_desc'] = 'Es busca l\'idnumber «{$a->category}» a {$a->host}. Si no casa o no connecta, l\'execució queda en error i t\'avisem amb la causa.';
$string['wz_tl3_title'] = 'Restauració llançada';
$string['wz_tl3_desc'] = '«Llançada» no és «acabada»: el progrés curs a curs se segueix al registre de CourseTransfer.';
$string['wz_tl4_title'] = 'La categoria queda recol·locada';
$string['wz_tl4_desc'] = 'Apareix sota la categoria d\'arxiu triada, encara restaurant cursos.';
$string['wz_tl5_title'] = 'Restauració completada de debò';
$string['wz_tl5_desc'] = 'Tots els cursos restaurats. Aquí comença el compte enrere de {$a->days} dies.';
$string['wz_tl6_title'] = 'Avís previ a l\'esborrament a l\'origen';
$string['wz_tl6_desc'] = 'Campana i correu, {$a->warningdays} dies abans, amb enllaç directe per cancel·lar l\'esborrament.';
$string['wz_tl7_title'] = 'S\'esborra de la plataforma origen';
$string['wz_tl7_desc'] = 'Si ningú no ho cancel·la, «{$a->category}» s\'elimina de {$a->host}. Aquí segueix arxivada.';
$string['wz_tl8_title'] = 'Candidats a poda anunciats';
$string['wz_tl8_desc'] = 'El de {$a->cutoff} o anterior s\'anuncia amb {$a->grace} dies de gràcia per excloure el que vulguis conservar.';
$string['wz_tl9_title'] = 'Poda de l\'arxiu';
$string['wz_tl9_desc'] = 'S\'eliminen de l\'arxiu els anys ja no coberts.';
$string['wz_tl_sameday'] = 'mateix dia';
$string['wz_tl_hours'] = 'pot trigar hores';
$string['wz_tl_aftergrace'] = 'després de la gràcia';
$string['wz_confirm_title'] = 'Aquesta tasca esborrarà contingut en una altra plataforma';
$string['wz_confirm_summary'] = 'El {$a->deletion} s\'eliminarà la categoria «{$a->category}» de {$a->host}. Rebràs un avís cancel·lable el {$a->warning}.';
$string['wz_confirm_check'] = 'Entenc que aquesta tasca esborrarà automàticament contingut de la plataforma origen i de l\'arxiu d\'aquesta plataforma.';
$string['wz_back'] = 'Enrere';
$string['wz_continue'] = 'Continuar';
$string['wz_save_new'] = 'Desar i activar la tasca';
$string['wz_save_edit'] = 'Desar canvis';
$string['wz_done_title'] = 'Tasca desada i activa';
$string['wz_done_desc'] = 'S\'executarà per primera vegada el {$a}. Podràs cancel·lar l\'esborrament a l\'origen des del panell mentre no hagi passat.';
$string['wz_done_back'] = 'Anar al panell';

// Pantalla de seguiment.
$string['exec_title'] = 'Execucions i esborraments';
$string['exec_desc'] = 'Què s\'està portant ara, què es llançarà després i què s\'esborrarà. Cada execució explica en quin punt del cicle és.';
$string['exec_scope'] = 'Tasca: {$a}';
$string['exec_scope_clear'] = 'Treure el filtre de tasca';
$string['exec_tab_live'] = 'En curs';
$string['exec_tab_deletions'] = 'Propers esborraments';
$string['exec_tab_history'] = 'Registre';
$string['exec_live_title'] = 'Execucions en curs';
$string['exec_live_empty'] = 'No hi ha cap execució en marxa ara mateix.';
$string['exec_live_line'] = 'Portant la categoria «{$a}»';
$string['exec_live_since'] = 'va començar fa {$a}';
$string['exec_fine_detail'] = 'Veure detall fi a CourseTransfer';
$string['exec_phase_launched'] = 'Llançada';
$string['exec_phase_restoring'] = 'Restaurant';
$string['exec_phase_completed'] = 'Completada';
$string['exec_stalled'] = 'Aquesta fase triga més del normal. Causa probable: el cron d\'alguna de les dues plataformes podria estar aturat.';
$string['exec_stalled_checks'] = 'Revisa els checks de salut';
$string['exec_refreshed'] = 'Actualitzat fa {$a}s';
$string['exec_upcoming_title'] = 'Properes execucions programades';
$string['exec_upcoming_empty'] = 'No hi ha properes execucions programades.';
$string['exec_upcoming_line'] = 'Portarà la categoria «{$a}»';
$string['exec_deletions_intro'] = 'Tot el que aquest connector esborrarà, en un sol lloc. Pots cancel·lar qualsevol esborrament mentre segueixi pendent: la categoria romandrà on és.';
$string['exec_deletions_empty'] = 'No hi ha cap esborrament pendent. Res no s\'eliminarà de forma automàtica per ara.';
$string['exec_urgent'] = 'urgent';
$string['exec_del_date'] = 'Data programada';
$string['exec_del_origin'] = 'Originat per';
$string['exec_filter_task'] = 'Tasca';
$string['exec_history_empty'] = 'No hi ha execucions registrades amb aquests filtres.';
$string['exec_col_date'] = 'Data';
$string['exec_col_what'] = 'Tasca i categoria';
$string['exec_col_type'] = 'Tipus';
$string['exec_col_request'] = 'Petició';
$string['exec_type_restore'] = 'Restaurar categoria';
$string['exec_type_prune'] = 'Poda de l\'arxiu';
$string['exec_status_cancelled'] = 'Esborrament cancel·lat';
$string['exec_status_deleted'] = 'Esborrada';
$string['exec_view_error'] = 'Veure error';
$string['exec_error_title'] = 'L\'execució va fallar';
$string['exec_showing'] = 'Es mostren {$a->shown} de {$a->total} entrades';
$string['exec_prev'] = 'Anterior';
$string['exec_next'] = 'Següent';

// Cicle d'esborraments / poda.
$string['deletionnotcancellable'] = 'Aquest esborrament no es pot cancel·lar: ja no està pendent.';
$string['prunenotexcludable'] = 'Aquesta categoria no es pot excloure: ja no és candidata a la poda.';

// Privacy API.
$string['privacy:metadata:local_ctm_tasks'] = 'Tasques de transferència configurades en aquest lloc. Es guarden referències d\'usuari per saber qui va crear cada tasca i qui rep els seus avisos.';
$string['privacy:metadata:local_ctm_tasks:usercreated'] = 'Usuari que va crear la tasca. Rep els avisos del seu cicle de vida.';
$string['privacy:metadata:local_ctm_tasks:notifyrecipients'] = 'Usuaris addicionals que reben els avisos d\'aquesta tasca.';
$string['privacy:metadata:local_ctm_executions'] = 'Execucions de les tasques de transferència. Es guarda una referència d\'usuari quan algú cancel·la un esborrament remot programat.';
$string['privacy:metadata:local_ctm_executions:deletecancelledby'] = 'Usuari que va cancel·lar l\'esborrament programat a la plataforma origen.';
$string['privacy:metadata:local_ctm_executions:deletecancelledat'] = 'Quan es va cancel·lar l\'esborrament programat.';
$string['privacy:metadata:local_ctm_prune'] = 'Candidats a la poda de l\'arxiu. Es guarda una referència d\'usuari quan algú exclou una categoria de la poda.';
$string['privacy:metadata:local_ctm_prune:excludedby'] = 'Usuari que va excloure la categoria de la poda.';
$string['privacy:metadata:local_ctm_prune:excludedat'] = 'Quan es va excloure la categoria de la poda.';