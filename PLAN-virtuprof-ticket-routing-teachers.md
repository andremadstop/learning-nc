# Plan: VirtuProf-Tickets an Dozenten oder Admins routen

Stand: 2026-03-19

## Ziel

VirtuProf-Tickets sollen nicht mehr pauschal nur bei Admins landen. Stattdessen soll das System unterscheiden:

- **fachliche / kursbezogene Anfragen**  
  -> an die **Dozenten des betroffenen Kurses**
- **technische / allgemeine App-Anfragen**  
  -> an **Admins**

Wichtig: Dieser Plan beschreibt nur die Implementierung. Es wird hier **noch nichts gebaut**.

---

## Ist-Zustand

### Heute technisch vorhanden

- Nutzer erstellt Tickets aus VirtuProf:
  - [app/src/components/VirtuProf.vue](app/src/components/VirtuProf.vue)
- Backend:
  - [app/lib/Controller/SupportTicketController.php](app/lib/Controller/SupportTicketController.php)
  - [app/lib/Service/SupportTicketService.php](app/lib/Service/SupportTicketService.php)
  - [app/lib/Db/SupportTicket.php](app/lib/Db/SupportTicket.php)
  - [app/lib/Db/SupportTicketMapper.php](app/lib/Db/SupportTicketMapper.php)
- Kontext wird bereits mitgespeichert:
  - `course_id`
  - `pool_id`
  - `question_id`
  - `duel_code`
  - `league_season_id`
  - plus `context_json`
- Antworten passieren aktuell nur im Admin-Bereich:
  - [app/src/components/AdminSettings.vue](app/src/components/AdminSettings.vue)

### Aktuelles Routing

Es gibt derzeit **kein echtes Routing**.

Praktisch bedeutet das:

- Ticket-Erstellung ist fuer eingeloggte Nutzer erlaubt
- **Lesen/Beantworten** ist nur ueber `@AdminRequired` moeglich
- also landen Tickets faktisch **immer bei Admins**

Das sieht man direkt in [app/lib/Controller/SupportTicketController.php](app/lib/Controller/SupportTicketController.php):

- `adminList()` -> `@AdminRequired`
- `answer()` -> `@AdminRequired`

---

## Produktidee

### Einfache Zielregel

Wenn ein Ticket eindeutig zu einem Kurs gehoert:

- Kurs vorhanden (`course_id`)
- Nutzer fragt aus Kurskontext

dann soll das Ticket **den Dozenten dieses Kurses** sichtbar werden.

Wenn kein Kursbezug existiert oder das Ticket als technisch/allgemein markiert ist:

- Ticket geht an **Admins**

### V1-Produktverhalten

Beim Erstellen eines Tickets in VirtuProf:

- Nutzer waehlt eine **Kategorie**
  - `Kursinhalt / Fachfrage`
  - `Technisches Problem`
  - `Frage zur Bedienung`
- wenn `course_id` vorhanden:
  - `Kursinhalt / Fachfrage` -> Dozent
  - `Technisches Problem` -> Admin
  - `Frage zur Bedienung` -> je nach Entscheidung Admin oder Dozent

Empfehlung fuer V1:

- **Fachfrage** -> Dozent
- **Technik / Bedienung** -> Admin

Das ist klar, einfach und leicht kommunizierbar.

---

## Empfohlene Implementierung

## 1. Routing explizit im Datenmodell machen

Aktuell steckt der Kontext nur implizit im Ticket. Fuer Routing und Inboxen braucht es explizite Felder.

### Neue Felder auf `learning_support_tickets`

Empfohlene neue Spalten:

- `category`  
  Werte z. B.:
  - `course_content`
  - `technical`
  - `usage`

- `routing_target_type`  
  Werte:
  - `admin`
  - `course_instructor`

- `routing_course_id` nullable  
  fuer Dozentenrouting

- optional `assigned_user_id` nullable  
  falls spaeter ein konkreter Bearbeiter uebernommen wird

### Warum das wichtig ist

Sonst muesste man bei jedem Zugriff neu aus dem Kontext raten:

- ist das ein Kursticket?
- ist es fachlich?
- wer darf es sehen?

Mit explizitem Routing wird das stabiler, einfacher und auditierbar.

---

## 2. Routing beim Erstellen zentral bestimmen

In [app/lib/Service/SupportTicketService.php](app/lib/Service/SupportTicketService.php) sollte beim `create(...)` nicht nur gespeichert, sondern sofort entschieden werden:

- Welche Kategorie hat das Ticket?
- Wer ist der Empfaenger-Typ?
- Ist das Ticket kursgebunden?

### Neue Hilfsmethode

Beispiel:

```php
private function resolveRouting(string $userId, array $context, ?string $category): array
```

Rueckgabe z. B.:

```php
[
  'category' => 'course_content',
  'routing_target_type' => 'course_instructor',
  'routing_course_id' => 20,
]
```

### Routing-Regel V1

```text
if course_id vorhanden und category == course_content
  -> course_instructor
else
  -> admin
```

---

## 3. Sichtbarkeit / Berechtigung umbauen

Heute sind Listen und Antworten nur `@AdminRequired`. Das ist fuer das neue Modell zu grob.

### Empfehlung

Neue Support-Ticket-Berechtigung **im Service** umsetzen, nicht nur ueber Controller-Annotation.

Warum:

- Admins sollen alles sehen
- Dozenten nur Tickets ihrer Kurse
- Nutzer nur ihre eigenen Tickets

Das ist feiner als reine `@AdminRequired`.

### Neue Listen-Endpunkte

#### Fuer Nutzer

Bleibt:

- `GET /api/support-tickets`

#### Fuer Admins

Neu oder bestehend erweitert:

- `GET /api/settings/admin/support-tickets?target=admin`

#### Fuer Dozenten

Neue Endpunkte, z. B.:

- `GET /api/settings/instructor/support-tickets`
- optional Filter:
  - `courseId`
  - `status`
  - `category`

### Antwort-Endpunkt

Statt `@AdminRequired`:

- normaler eingeloggter Zugriff
- im Service pruefen:
  - Admin darf immer
  - Kursdozent darf nur antworten, wenn
    - `routing_target_type = course_instructor`
    - und er Dozent genau dieses Kurses ist

Das benoetigt Zugriff auf die bestehende Kursrollenlogik:

- [app/lib/Service/CourseService.php](app/lib/Service/CourseService.php)
- dort existiert bereits `isInstructorOfCourse(...)` intern

Empfehlung:

- daraus eine **oeffentliche, kleine Berechtigungsmethode** machen, z. B.:

```php
public function canManageCourse(int $courseId, string $userId): bool
```

---

## 4. UI-Plan

## A. VirtuProf Ticket-Erstellung

In [app/src/components/VirtuProf.vue](app/src/components/VirtuProf.vue):

- Ticketformular um kleine Kategorie-Auswahl erweitern
- wenn Kurskontext vorhanden:
  - Kategorien anzeigen:
    - `Fachfrage zum Kurs`
    - `Technisches Problem`
    - `Frage zur Bedienung`
- ohne Kurskontext:
  - nur `Technisches Problem`
  - `Frage zur Bedienung`

Optionaler UI-Text:

- `Fachfragen gehen an den zuständigen Dozenten.`
- `Technische Fragen gehen an den Admin.`

## B. Dozenten-Inbox

Nicht in den globalen Admin-Settings verstecken.

Empfehlung:

- neuer Bereich in [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue)
  - Tab oder Section `Anfragen`
  - pro Kurs nur die Tickets dieses Kurses

Warum das besser ist:

- Dozent denkt in Kursen
- Ticketkontext ist dort schon vorhanden
- weniger globale Ueberladung

Alternative V1:

- eigener Bereich in `InstructorDashboard`

Meine Empfehlung:

- **kursgebundene Tickets direkt im Kurs**
- technische Tickets bleiben im Admin-Bereich

## C. Admin-Inbox

In [app/src/components/AdminSettings.vue](app/src/components/AdminSettings.vue):

- nur noch Tickets mit `routing_target_type = admin`
- optional Spaeter:
  - separater Tab `Admin-Tickets`
  - Filter nach Kategorie und Status

---

## 5. Statusmodell

Das aktuelle Statusmodell ist sehr einfach:

- `open`
- `answered`

Das reicht fuer V1.

Spaeter sinnvoll:

- `open`
- `in_progress`
- `answered`
- `closed`

Aber fuer den ersten Routing-Ausbau wuerde ich das bewusst **nicht gleichzeitig aufblasen**.

---

## 6. Notifications / Sichtbarkeit

V1 muss nicht sofort echte Nextcloud-Benachrichtigungen haben.

Genug fuer den Anfang:

- Dozent sieht Tickets in der Kursansicht
- Admin sieht technische Tickets in AdminSettings
- Nutzer sieht Antworten weiter in VirtuProf

Spaeter sinnvoll:

- Badge `neue Anfrage`
- Badge `neue Antwort`
- evtl. Nextcloud Notification API

---

## 7. Datenfluss V1

### Fachfrage

1. Nutzer ist in Kurs 20
2. VirtuProf -> `Fachfrage zum Kurs`
3. Ticket wird gespeichert mit:
   - `category = course_content`
   - `routing_target_type = course_instructor`
   - `routing_course_id = 20`
4. Dozent von Kurs 20 sieht Ticket im Kurs
5. Dozent antwortet
6. Nutzer sieht Antwort in VirtuProf

### Technikfrage

1. Nutzer meldet Problem
2. Ticket wird gespeichert mit:
   - `category = technical`
   - `routing_target_type = admin`
3. Admin sieht Ticket in AdminSettings
4. Admin antwortet
5. Nutzer sieht Antwort in VirtuProf

---

## 8. Benoetigte Codebereiche

### Sicher betroffen

- [app/lib/Db/SupportTicket.php](app/lib/Db/SupportTicket.php)
- [app/lib/Db/SupportTicketMapper.php](app/lib/Db/SupportTicketMapper.php)
- [app/lib/Service/SupportTicketService.php](app/lib/Service/SupportTicketService.php)
- [app/lib/Controller/SupportTicketController.php](app/lib/Controller/SupportTicketController.php)
- [app/lib/Service/CourseService.php](app/lib/Service/CourseService.php)
- [app/appinfo/routes.php](app/appinfo/routes.php)
- [app/src/components/VirtuProf.vue](app/src/components/VirtuProf.vue)
- [app/src/components/VirtuProfBubble.vue](app/src/components/VirtuProfBubble.vue)
- [app/src/components/AdminSettings.vue](app/src/components/AdminSettings.vue)
- [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue)

### Neu hinzukommend

- neue Migration fuer Routing-Spalten
- evtl. kleine Vue-Komponente fuer Ticketliste/Antwortformular, falls man Admin- und Dozenten-UI nicht duplizieren will

---

## 9. Empfohlene Implementierungsreihenfolge

1. **DB erweitern**
   - `category`
   - `routing_target_type`
   - `routing_course_id`
   - optional `assigned_user_id`

2. **SupportTicketService**
   - Routing beim Erstellen bestimmen
   - Listenmethoden:
     - `listMine`
     - `listAdmin`
     - `listInstructorForCourse`
   - Berechtigtes Antworten pruefen

3. **CourseService**
   - oeffentliche Methode fuer `canManageCourse(...)`

4. **Controller/Routen**
   - Instructor-List-Endpoint
   - Antwort-Endpunkt von `admin only` auf servicebasierte Rechtepruefung umstellen

5. **VirtuProf UI**
   - Kategorieauswahl beim Erstellen

6. **Dozenten-UI**
   - Ticketliste im Kurs

7. **Admin UI**
   - nur noch technische/admin-geroutete Tickets

8. **Smokes**
   - Student -> Fachfrage -> Dozent sieht/antwortet
   - Student -> Technikfrage -> Admin sieht/antwortet
   - Dozent darf fremde Kurstickets nicht sehen
   - Student sieht Antwort in VirtuProf

---

## 10. Risiken / Knackpunkte

### 1. Ein Ticket kann zwar Kurskontext haben, aber trotzdem technisch sein

Deshalb reicht `course_id` allein nicht als Routingkriterium.
Die Kategorie muss explizit mitgespeichert werden.

### 2. Co-Instructor / mehrere Dozenten

Die App kennt bereits:

- Kurs-Owner `courses.instructor_id`
- zusaetzliche Mitglieder mit Rolle `instructor`

Das ist gut, aber die Ticketliste muss sauber beide Faelle erlauben.

### 3. Alte Tickets ohne Routingfelder

Migration muss mit Legacy-Tickets umgehen.

Empfehlung V1:

- alte Tickets default:
  - `routing_target_type = admin`
  - `category = technical`

Dann geht nichts verloren.

---

## Klare Empfehlung

Nicht einfach Admin-Tickets fuer Dozenten mit oeffnen, sondern **explizites Routing** einfuehren.

Warum:

- fachlich sauber
- spaeter ausbaubar
- keine unklaren Sichtbarkeiten
- Tickets koennen je nach Typ bei der richtigen Person landen

---

## V1-Kurzfassung

Wenn Claude das umsetzt, sollte V1 so aussehen:

- VirtuProf-Ticketformular bekommt Kategorieauswahl
- Ticket speichert Routingziel explizit
- Kursbezogene Fachfragen gehen an Kursdozenten
- Technik-/Bedienfragen gehen an Admins
- Dozenten sehen und beantworten ihre Tickets im Kurs
- Admins sehen und beantworten nur admin-geroutete Tickets
- Nutzer sieht wie bisher alles in `Meine Anfragen`

Das ist einfach genug fuer einen ersten sauberen Ausbau und passt gut auf die bestehende Architektur.
