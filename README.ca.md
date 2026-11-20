<!-- Versió en català. English version: README.md · Versión en español: README.es.md -->
<p align="center">
  <img src="pix/logo.png" alt="" width="280">
</p>

<h1 align="center">Gestor de transferències de cursos</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.2.0-informational" alt="Versió">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.3-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="Llicència">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Fet per Tresipunt"></a>
</p>

<p align="center"><b>Arxiu programat de categories de cursos entre plataformes Moodle — esborraments anunciats i cancel·lables.</b></p>

<p align="center"><a href="README.md">🇬🇧 English</a> · <a href="README.es.md">🇪🇸 Español</a> · <b>Català</b></p>

El Gestor de transferències de cursos automatitza l'arxiu anual de categories
sobre [Course Transfer](https://github.com/3iPunt/moodle-local_coursetransfer):
una tasca porta una categoria completa d'una altra plataforma un cop l'any, la
guarda al teu arxiu i, passada la retenció que indiquis, l'esborra de l'origen.
No afegeix maquinària de transferència pròpia —orquestra Course Transfer— i com
que esborra contingut en una plataforma **remota** mesos després, tot
esborrament s'anuncia amb antelació, es veu en pantalla i es pot cancel·lar
fins al moment en què passa.

---

## ✨ Què fa

- **Panell de gestió** — comprovacions de salut dels requisits (Course
  Transfer, cron, tasca programada del connector), una agenda cronològica
  **«Properament»** que ajunta les properes execucions amb els esborraments
  pendents, i targetes de tasca amb la seva última execució, interruptor
  d'activació i un «Executar ara» amb salvaguardes.
- **Assistent de tasca guiat** — cinc passos que verifiquen sobre la marxa:
  **provar el patró de categoria contra l'origen** abans de desar, programar
  en llenguatge normal (amb el cron com a mode avançat) i vista prèvia en viu
  de les properes execucions, veure les **dates reals** de cada esborrament
  que provocarà la tasca, i revisar la línia de temps completa abans de
  confirmar.
- **Pantalla de seguiment** — què s'està portant ara, què es llançarà després
  i tot el que s'esborrarà, amb un registre unificat; cada petició enllaça al
  seu detall fi a Course Transfer.
- **Nou avisos del cicle de vida** — campana i correu a cada fita, inclòs un
  **avís previ amb enllaç per cancel·lar** abans d'esborrar a l'origen, un avís
  quan un esborrament queda **retingut** per una comprovació de seguretat i la
  petició d'**aprovar** el primer esborrament d'una tasca.
- **Panys de seguretat** — l'original mai no s'esborra si la restauració no
  consta completada, si algun curs restaurat ja no és a l'arxiu o si l'origen ha
  canviat des d'aleshores; el primer esborrament de cada tasca espera una
  persona, i un **fre d'emergència** pausa tots els esborraments de cop (vegeu
  *Salvaguardes*).
- **Simulació** — què arxivaria, esborraria i podaria una tasca ara mateix, sense
  tocar res.

## 🧭 Casos d'ús

- **Arxiu anual d'un curs acadèmic** — cada 1 de setembre, portar de la
  plataforma de producció la categoria de l'any que acaba a una instància
  d'arxiu, i retirar-la de producció un mes més tard, quan la còpia ja s'ha
  revisat.
- **Alliberar espai a producció de manera predictible** — mantenir en línia
  només els anys vigents: l'arxiu conserva els últims N anys i anuncia els més
  antics com a candidats a poda, donant temps a excloure el que convingui
  conservar.
- **Consolidar diversos orígens en un mateix arxiu** — una tasca per
  plataforma d'origen, cadascuna amb el seu patró i la seva retenció, totes
  visibles al mateix panell.
- **Arxivar sense esborrar res** — amb una retenció alta (o cancel·lant
  l'esborrament cada any) s'obté la còpia anual automàtica mentre l'original
  roman intacte a l'origen.

## ⚙️ Com funciona

- Una **tasca** defineix: la plataforma d'origen, una **màscara de nom** que
  reconeix les categories anuals, la categoria d'arxiu de destinació, quan
  s'executa i les dues finestres de retenció.
- **Rota sola.** La màscara no és una categoria: reconeix tota la sèrie. A
  l'execució del curs A la tasca arxiva A−P i esborra definitivament A−P−V, on
  **P** són els cursos que es queden en producció i **V** els que es guarden a
  l'arxiu. Ningú edita la tasca al setembre.

  | Màscara | Reconeix | Marcadors |
  |---|---|---|
  | `CAT-{YEAR}-{NEXTYEAR}` | `CAT-2025-2026` | anys de quatre xifres |
  | `CAT-{YEAR}-{NEXTYY}` | `CAT-2025-26` | mixt |
  | `CAT-{YY}-{NEXTYY}` | `CAT-25-26` | anys de dues xifres |
  | `SJD{YEAR}` | `SJD2025` | un sol any |
  | `{ANY}-{YEAR}-{NEXTYEAR}` | `GINF-2025-2026`, `MED-2025-2026` | una tasca, tots els graus |

  Amb P=2 i V=4, l'execució del 2027/28 deixa en producció 2027/28 i 2026/27,
  manté quatre cursos a l'arxiu i esborra definitivament 2021/22. L'assistent i
  la **vista de cicle de vida** (`task.php?id=N`) projecten aquesta taula sis
  execucions endavant, per no haver-ho de calcular de memòria.
- A la data programada la tasca llegeix les categories de l'origen, tria el curs
  que ha sortit de la finestra de producció i demana a Course Transfer que el
  restauri; després el recol·loca sota la categoria d'arxiu triada.
- Una restauració només està **completada** quan Course Transfer ho confirma;
  l'esborrament a l'origen no s'executa mai abans (vegeu *Salvaguardes*).
- Abans d'esborrar a l'origen s'envia un **avís previ** (dies configurables) i
  es tornen a fer totes les comprovacions de seguretat. Si alguna falla,
  l'esborrament es **reté** i s'informa, en lloc d'executar-se.
- Les categories que van arribar a l'arxiu **sense** la tasca són invisibles per
  a la poda a propòsit: és el que protegeix el contingut aliè. Adoptar-ne una
  perquè la tasca pugui esborrar-la és una decisió explícita, auditada i
  reversible des de la vista de cicle de vida.
- Tot és **asíncron**: depèn del **cron** de Moodle a les dues plataformes,
  així que el cron ha d'estar corrent a totes dues.

## 📋 Requisits

| Requisit | Versió |
|---|---|
| Moodle | 4.5, 5.1 o 5.3 |
| PHP | 8.1+ |
| Altres connectors | `local_coursetransfer` **2.2.0 o superior en aquesta plataforma** (la plataforma d'origen remota només necessita els seus serveis web de backend estables; es recomana 2.2.0) |
| Cron | En marxa a **les dues** plataformes |

## 🚀 Instal·lació

1. Copiar el codi a `local/coursetransfermanager/` (`public/local/…` a
   Moodle 5.x).
2. Completar la instal·lació des d'**Administració del lloc › Notificacions**
   (o `php admin/cli/upgrade.php --non-interactive`).
3. Purgar les memòries cau (**Administració del lloc › Desenvolupament ›
   Purgar memòries cau**).
4. Comprovar que les plataformes d'origen ja estan aparellades a Course
   Transfer i que **el cron corre a les dues plataformes**.

## 🔧 Paràmetres

A **Administració del lloc › Extensions › Extensions locals › Gestor de
transferències de cursos**:

| Paràmetre | Efecte |
|---|---|
| **Dies d'avís abans d'esborrar a l'origen** | Amb quanta antelació s'envia l'avís previ cancel·lable abans d'un esborrament programat. Per defecte: 7, mínim 1. |
| **Dies de gràcia abans de podar l'arxiu** | Temps entre l'anunci dels candidats a la poda i el seu esborrament, per poder excloure'ls. Per defecte: 7, mínim 1. |
| **Retenir l'esborrament si l'origen ha canviat (R3)** (`holdiforiginchanged`) | Activat per defecte. Vegeu *Salvaguardes*. |
| **Aprovar el primer esborrament de cada tasca** (`requirefirstdeletionapproval`) | Activat per defecte. Vegeu *Salvaguardes*. |
| **Pausar tots els esborraments (fre d'emergència)** | Mentre està actiu no s'executa cap esborrament ni poda i les seves dates es van posposant, de manera que treure la pausa mai no provoca un embús. Les restauracions no es veuen afectades. |
| **Mes d'inici del curs acadèmic** | Es fa servir per saber quin curs està en marxa quan s'executa una tasca. Abans d'aquest mes el curs en marxa segueix essent l'anterior: al maig del 2026 el curs és 2025/26. Per defecte: setembre. |
| **Màxim de categories arxivades per execució** | Límit perquè un curs endarrerit no inundi l'origen de restauracions simultànies. |

Les finestres de retenció (**cursos en producció** i **cursos a l'arxiu**) són de
cada tasca, no del lloc: orígens diferents poden guardar quantitats diferents
d'història.

Els canals d'avís es gestionen amb les preferències de notificació estàndard
de Moodle, per lloc i per usuari.

## 🛡️ Salvaguardes

La tasca parla amb l'origen **com el seu creador** (o com qui prem «Executa
ara»): aquest usuari necessita un compte equivalent a l'origen amb les
capacitats que Course Transfer hi exigeix. L'esborrament a l'origen es programa
als **dies de retenció** de la tasca des del llançament, però mai no s'executa
abans que Course Transfer doni la restauració per **completada**.

Esborrar a l'origen és l'únic pas que elimina contingut que potser no existeix
enlloc més. Just abans d'executar-lo es fan aquestes comprovacions; si alguna
falla, l'esborrament queda **retingut**, s'ajorna i s'avisa, mai no s'executa en
silenci:

| Salvaguarda | Què comprova | Paràmetre |
|---|---|---|
| **R1 — restauració verificada** | Course Transfer ha donat la restauració per completada (no només llançada) i verificada: sense mòduls ni fitxers que faltin i amb els cursos restaurats iguals a l'origen. | Sempre activa |
| **R2 — arxiu intacte** | Tots els cursos d'aquesta restauració continuen en aquesta plataforma, dins de la categoria arxivada. | Sempre activa |
| **Identitat utilitzable** | El creador de la tasca existeix i és actiu: mai no es demana res a l'origen amb una altra identitat. | Sempre activa |
| **R3 — origen sense canvis** | La categoria de l'origen continua tenint els cursos que es van arxivar; cursos nous o desapareguts, o un origen que no respon, retenen l'esborrament. L'origen no informa de dates de modificació, així que només es compara la llista de cursos. | `holdiforiginchanged` (activat) |
| **Aprovació del primer esborrament** | El primer esborrament de cada tasca, i el primer després de canviar-ne la màscara, els anys o l'origen, espera a *Execucions i esborraments* que un gestor l'aprovi. Després d'aprovar-lo, les salvaguardes es tornen a comprovar. | `requirefirstdeletionapproval` (activat) |
| **Avís previ** | S'envia un avís cancel·lable abans de l'esborrament, mai a la mateixa passada del cron. | Dies d'avís (≥ 1) |
| **Fre d'emergència** | Mentre està actiu no s'executa cap esborrament ni poda i les dates s'ajornen. | Pausar tots els esborraments |

La poda de l'arxiu té les seves pròpies garanties: només considera categories que
la tasca va portar o adoptar, anuncia els candidats amb un període de gràcia (es
poden excloure) i torna a comprovar cadascun abans d'esborrar-lo; el que ja no
compleix la política queda exclòs per sempre. A l'origen, Course Transfer aplica
les seves pròpies proteccions (retenció a la paperera, esdeveniments d'auditoria).

**Simula** una tasca (botó a la vista del cicle de vida) per veure què arxivaria,
quins esborraments s'executarien, quedarien retinguts o esperarien aprovació i què
podaria, sense canviar res. Cada esborrament, retenció, aprovació, cancel·lació i
poda queda també com a esdeveniment als registres del lloc.

## 🗑️ Desinstal·lació

En eliminar el connector s'esborren les seves tasques, l'historial
d'execucions i els registres de poda. No es toca res més: les categories ja
arxivades es queden on són i no es torna a programar cap esborrament.

## 🛠️ Desenvolupament

```bash
# Tests unitaris
vendor/bin/phpunit --testsuite local_coursetransfermanager_testsuite

# Compilació de JavaScript (després d'editar amd/src)
npx grunt amd --root=local/coursetransfermanager
```

## 📄 Llicència

[GNU GPL v3 o posterior](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>
