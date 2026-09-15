<!-- Versió en català. English version: README.md · Versión en español: README.es.md -->
<p align="center">
  <img src="pix/logo.png" alt="" width="280">
</p>

<h1 align="center">Gestor de transferències de cursos</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.1.1-informational" alt="Versió">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.1-orange?logo=moodle" alt="Moodle"></a>
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
- **Vuit avisos del cicle de vida** — campana i correu a cada fita, inclòs un
  **avís previ amb enllaç per cancel·lar** abans d'esborrar a l'origen, i un
  avís quan un esborrament queda **retingut** per una comprovació de
  seguretat.
- **Panys de seguretat** — l'original mai no s'esborra si la restauració no
  consta completada i la còpia arxivada no segueix aquí amb els seus cursos; a
  més, un **fre d'emergència** que pausa tots els esborraments de cop.

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
  aquí comença el compte enrere de la retenció.
- Abans d'esborrar a l'origen s'envia un **avís previ** (dies configurables) i
  es comprova que la còpia arxivada hi és realment. Si no hi és, l'esborrament
  es **reté** i s'informa, en lloc d'executar-se.
- Les categories que van arribar a l'arxiu **sense** la tasca són invisibles per
  a la poda a propòsit: és el que protegeix el contingut aliè. Adoptar-ne una
  perquè la tasca pugui esborrar-la és una decisió explícita, auditada i
  reversible des de la vista de cicle de vida.
- Tot és **asíncron**: depèn del **cron** de Moodle a les dues plataformes,
  així que el cron ha d'estar corrent a totes dues.

## 📋 Requisits

| Requisit | Versió |
|---|---|
| Moodle | 4.5 – 5.1 |
| PHP | 8.1+ |
| Altres connectors | `local_coursetransfer` **2.0.0 o superior en aquesta plataforma** (la plataforma d'origen remota només necessita els seus serveis web de backend estables) |

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
| **Dies d'avís abans d'esborrar a l'origen** | Amb quanta antelació s'envia l'avís previ cancel·lable abans d'un esborrament programat. Per defecte: 7. |
| **Dies de gràcia abans de podar l'arxiu** | Temps entre l'anunci dels candidats a la poda i el seu esborrament, per poder excloure'ls. Per defecte: 7. |
| **Pausar tots els esborraments (fre d'emergència)** | Mentre està actiu no s'executa cap esborrament ni poda i les seves dates es van posposant, de manera que treure la pausa mai no provoca un embús. Les restauracions no es veuen afectades. |
| **Mes d'inici del curs acadèmic** | Es fa servir per saber quin curs està en marxa quan s'executa una tasca. Abans d'aquest mes el curs en marxa segueix essent l'anterior: al maig del 2026 el curs és 2025/26. Per defecte: setembre. |
| **Màxim de categories arxivades per execució** | Límit perquè un curs endarrerit no inundi l'origen de restauracions simultànies. |

Les finestres de retenció (**cursos en producció** i **cursos a l'arxiu**) són de
cada tasca, no del lloc: orígens diferents poden guardar quantitats diferents
d'història.

Els canals d'avís es gestionen amb les preferències de notificació estàndard
de Moodle, per lloc i per usuari.

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
