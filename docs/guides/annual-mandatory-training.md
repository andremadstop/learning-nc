# Annual mandatory training with certificates

This guide covers one setup: a small organisation where everyone studies the material on their own, takes an exam, and gets a certificate that is valid for one year. Each person renews when their own certificate is about to expire. The certificates are your audit evidence. There is no AI, no gamification and no spaced repetition (Leitner).

It describes Learning 5.12.0. A few things are not in the app yet. They are marked **(coming)**. Unfamiliar terms are explained in the [glossary](#glossary) at the end.

You need two kinds of account:
- a **Nextcloud administrator** for step 1;
- an **instructor** for steps 2–10. An instructor is a Nextcloud administrator or someone named under "Instructors (groups and people)" (see step 1).

**Contents**

1. [Prepare the server](#step-1--prepare-the-server-administrator-once)
2. [Create the course](#step-2--create-the-course)
3. [Provide the learning material](#step-3--provide-the-learning-material)
4. [Create the question pools and add them to the course](#step-4--create-the-question-pools-and-add-them-to-the-course)
5. [Set up certification and the exam](#step-5--set-up-certification-and-the-exam)
6. [Switch off what you don't need](#step-6--switch-off-what-you-dont-need)
7. [Enrol your people](#step-7--enrol-your-people)
8. [What learners see (and how to test it)](#step-8--what-learners-see-and-how-to-test-it)
9. [The yearly renewal](#step-9--the-yearly-renewal)
10. [Audit evidence](#step-10--audit-evidence)

---

## Step 1 — Prepare the server (administrator, once)

Open **Administration settings → Learning**.

1. **Signing key for certificates.** Until this key exists, nobody gets a certificate, even after passing. At the top of the page, click **Generate signing key**. You can also run this on the server command line:
   ```
   occ learning:cert:init-issuer
   ```
   When it worked, the page says "Signing key generated. Certificates are now active."
2. **Gamification enabled:** switch it **off**, and do it **before you create the course**. A course created while gamification is off starts with Duel, Gameshow, League and Adventure switched off. Courses that already exist are not changed. With gamification off, learners see no XP, levels, streaks, badges or leaderboard, and League, Arena and Adventure are hidden for everyone.
3. **AI provider:** choose **Disabled**. This hides the AI chat, the "Knowledge" tab and the "Extract" buttons.
4. **Daily Challenge enabled:** switch it off if you don't want it.
5. **Instructors (groups and people):** if the person who runs the training is not a Nextcloud administrator, add that person, or a group they belong to. You can pick several groups and individual people. They can then create courses and question pools. Nextcloud administrators always count as instructors. If nothing is selected, the group `learning-instructors` applies. Leave **Allow course-based instructor fallback** switched off (the page recommends this).
6. **Exam attempts per 24h (per user, pool and course)** (default 5) and **Exam cooldown (minutes)** (default 10): change these if you want a stricter or looser retake rule. Learners see these numbers.
7. **Operator details (imprint and privacy notice):** fill in name, postal address and contact email, then click **Save operator details**.
8. Click **Save**.

Outside the Learning page:

- **Organisation name and logo on the certificate.** The certificate shows the name and logo from Nextcloud's **Theming** settings at the top, both on screen and when printed. The verification page shows them too. The name is written into the signed certificate when it is issued, and changing it later does not change existing certificates. Set it before the first real certificate is issued. (The screenshots in this guide come from a test instance with the default name "Nextcloud".)
- **Background jobs (Cron) must run.** Expiry reminders, the renewal window and the closing of expired periods all run as Nextcloud background jobs. Check under **Administration settings → Basic settings** that background jobs use Cron and ran recently.
- **E-mail.** Learning does not send e-mail itself. It sends Nextcloud notifications (the bell icon): "You were enrolled in the course …", "Certificate issued: …", and the expiry reminders. These reach people by e-mail only if Nextcloud is set up to send notification e-mails: an e-mail server under Basic settings, an e-mail address on each account, and e-mail reminders switched on in the Notifications settings.
- **How long evidence is kept.** By default, certificate and audit data are anonymised **3 years after the certificate's issue date**. After that, the certificate can no longer be verified. If your regulation asks for a longer period, change it before you go live, for example:
  ```
  occ config:app:set learning retention_years --value=<years your regulation requires>
  ```
  The value `0` turns automatic anonymisation off.

---

## Step 2 — Create the course

1. In Learning, open **Courses** and click **+ Create Course**.
2. Enter a **Title**, for example "Annual safety training", and optionally a **Description**.
3. Leave **Nextcloud group** empty for now. You will enrol people in step 7.
4. Click **Create**.

![The "Create Course" dialog with a title filled in and the Nextcloud group left empty](img/mandatory-training-create-course.png)

Open the course. As an instructor, you see the areas **Learning space**, **Participants**, **Competition**, **Communication** and **Administration**.

---

## Step 3 — Provide the learning material

The material lives in a normal Nextcloud folder in **your own** files (you are the course owner). Learners do not need a share. The app shows them the files.

1. In your Nextcloud Files, create a folder, for example `/Training 2027`, and upload your PDF or Markdown files.
2. In the course, go to **Learning space → Learning materials**.
3. Under **Material folder**, click **Choose folder…**, pick the folder and click **Link this folder**. (Or type the path and click **Link folder**.) The folder is linked and scanned straight away.
4. Whenever you add or replace files later, click **Scan folder**. Otherwise learners won't see the new files.

The page says: "Course members can open every PDF and Markdown file in this folder." Learners click a file name to open it in the browser. For learners, **Learning materials** is the first tab in the course's learning space.

Leave **Topics** alone. It is a filter by chapter and not needed here.

---

## Step 4 — Create the question pools and add them to the course

A **pool** is a set of exam questions. You can use one pool or several, for example one per topic.

**Create pools:**
- **One at a time:** open **Pools** in the top navigation and click **+ Create pool**. Then open the pool and go to the **Manage** tab. (Opening a pool lands on the Training tab. Opening it on Manage first is **(coming)**, #46.) In Manage, use **+ Add question**, or **Import** for a CSV or JSON file. The import dialog shows the CSV format.
- **Several at once:** in **Pools**, click **Import pools** and drop a JSON file that contains several pools. Click **Check file** to preview, then **Import pools (n)**.

**Add them to the course:**
1. In the course, go to **Learning space → Pools** and click **+ Add Pool**.
2. Tick the pools and click **Add n pool(s)**.

Each pool in the course has a **Rules** button. In step 5 you can use it to set the exam size for that pool. The other switches in it do not affect the certificate. As the dialog says: "Only the "Required pools" selection under Administration → Course rules → Certification decides about the certificate."

---

## Step 5 — Set up certification and the exam

Go to **Administration → Course rules** and scroll to **Certification**.

1. Switch on **Enable certification**.
2. **Minimum score (%)**: your pass mark, for example 75.
3. **Required pools**: tick every pool a person must pass. Each required pool needs its **own** passed exam at the pass mark. If you tick none, any passed exam in the course is enough.
4. **Validity duration (days, 0 = no expiry)**: `365` for a yearly training.
5. Optional: **Exam scope (default for all pools)**, see below.
6. Click **Save** in this section. Each section on this page has its own Save button.

![The Certification section: Enable certification on, minimum score 75 %, two required pools ticked, validity 365 days](img/mandatory-training-certification-settings.png)

**How big the exam is.** Each exam is about **one pool**. You decide how many questions it has and how long it lasts, in two places:

- **For one pool:** in **Learning space → Pools**, click **Rules** on the pool. Under **Exam in this course**, set **Questions per exam** and **Time limit (minutes)**, then click **Save Rules**. The number of questions can't be higher than the number of questions in the pool.
- **For every pool of the course:** **Exam scope (default for all pools)** in the Certification section, with the same two fields. It applies to every pool that has no value of its own in its rules.

The questions are drawn at random from the pool on every attempt, so each attempt is different. If you set a number of questions but no time limit, the exam allows 90 seconds per question. If you leave everything empty, the exam follows the pool size: all of the pool's questions, up to 90 (with more than 90, a random selection on every attempt), 90 seconds per question, at most 2 hours. Learners get no feedback until the end.

**The certificate** shows: the organisation name from Theming, "Awarded to" with the person's name, the course title, **Course elements** (the required pools), Result, Minimum score, Issued on, Valid until, a QR code and the Certificate ID. If you tick no required pools, the certificate has no "Course elements". Your own wording on the certificate, a switch for the course elements, and a stamp or signature image are **(coming)**, #43.

---

## Step 6 — Switch off what you don't need

Still under **Administration → Course rules**, in **Course rules — Learning modes**:

| Switch | Recommended | Why |
|---|---|---|
| **Training** | on | Practice with feedback after each answer. It does not count for the certificate. |
| **Leitner** | **off** | Spaced repetition. It is not needed here, and it is on in a new course. With it off, the server refuses Leitner to learners who have the pool only through this course, and the pool leaves their Smart Queue and due reminders. |
| **Exam** | **on** | Without it, nobody can be certified. |
| **Class register** | off | A member directory with profiles and kudos. On in a new course. |
| **Learning partner** | off | Matches learners who can help each other with a topic. On in a new course. |
| **Completion tab** | either | With certification on, learners get the **Completion & certificate** tab anyway. The switch only adds their summary statistics to it. |

Click **Save** under the switches.

If you switch off **Exam** by mistake, the certification section warns you: "The "Exam" learning mode is switched off in this course. Certificates are only earned with exams started here in the course, so nobody can receive a certificate."

**Game modes** (Duel, Gameshow, League, Adventure) sit in a collapsed area below the switches. Its heading shows how many are active. It should say **0 active**.

Further down on the same page:
- **Course rules — tools:** untick **Show simulator tools in this course**, then click **Save tools**. The simulators are IT practice tools (subnetting, DNS and so on) and are on in a new course.
- **Exam date:** leave it empty. It only drives a countdown and does not open or close the exam. People take the exam whenever they are ready.
- **Practice exam:** optional. If you switch on **Offer a practice exam**, learners get a practice run that never counts.

---

## Step 7 — Enrol your people

Go to **Participants → Members**.

1. In **People or groups**, type part of a name or a group name, and pick from the suggestions. You can pick several.
2. Leave **Role** on **Learner**. **Instructor** is for people who run the course, and they never get a certificate in it.
3. Click **Add**. A summary shows who was added, who was already enrolled and who was not found.

Everyone added gets the notification "You were enrolled in the course …".

**Groups are a snapshot.** "Choosing a group enrols everyone who is in the group right now. People who join the group later are not added automatically." When someone new joins your organisation, add them here.

---

## Step 8 — What learners see (and how to test it)

A learner opens **Courses** and then your course. They see **Learning space**, **Completion & certificate** and **Communication**, plus **Participants** only if something is left in it (for example the Class register while it is on).

1. **Learning space → Learning materials:** read the material.
2. **Learning space → Training** (optional): practise with feedback.
3. **Learning space → Exam:** the tab shows the pass mark, and for each required pool the best result and whether it is passed. To take the exam, the learner clicks a pool and then **Start Exam**.
   Before starting, the exam screen must say: "This exam counts towards your certificate. Pass mark: 75%. No feedback until the end." The card above **Start Exam** shows the number of questions and the time limit.
   If that line is missing, the learner is in the wrong place. **Only exams started inside the course count.** An exam started from the top **Pools** menu does not count.

   ![The exam screen inside the course: "This exam counts towards your certificate. Pass mark: 75%." and the Start Exam button with questions and time limit](img/mandatory-training-exam-start.png)

4. After the exam, the result screen says **Passed** or **Not passed**. If the last required pool was just passed, it shows **Certificate issued** and **Open certificate**. If pools are still missing, it says "Pool passed — still required: …". Learners also see the retake rules (attempts per pool in this course in 24 hours, minimum gap between two attempts).
5. **Completion & certificate:** shows "What you need for the certificate", every required pool with its best result, the validity, and the certificate. Click **View certificate**, then **Print**, to save it as a PDF.

   ![A printed certificate: course title, "Awarded to", course elements, result, minimum score, issue and expiry date, QR code and certificate ID](img/mandatory-training-certificate.png)

6. **Communication → Ask a question:** learners write to the course's instructors. Only they and the learner see the question and the answer. Instructors find it under **Communication → Questions from learners** and get a notification. The learner is notified when it is answered.

**Test it with a learner account, not your own.** Instructors never get a certificate in their own course. **Show student view** (under Settings → My Settings) only previews the layout and leaves no certificate behind.

---

## Step 9 — The yearly renewal

Once set up, the cycle runs by itself. It needs background jobs (step 1).

| When | What happens |
|---|---|
| 30 days before expiry | Renewal opens. The learner gets the notification "Your certificate "…" expires in 30 days", and the completion tab shows **Renewal open**. |
| 7 days before expiry | A second reminder. |
| Renewal exam passed | The learner must pass a new exam in **every** required pool inside the window. Then a new certificate is issued. If this happens before the expiry date, **its validity continues from the old expiry date**, so renewing early costs nothing. If it happens after the expiry date, the validity starts on the exam day. The old certificate stays valid until its own end date. |
| Expiry + 14 days, not renewed | The old period is closed and a new one opens. The next passed exam issues a fresh certificate, valid from that day. |

Only exams taken inside the renewal window count. Last year's exam can never renew a certificate. Before renewal opens, the completion tab says "Renewal opens on {date}".

Optional, for administrators (command line): `recert_reminder_thresholds` (default `30,7`; the largest value also sets when renewal opens) and `recert_grace_days` (default `14`). For example:
```
occ config:app:set learning recert_reminder_thresholds --value=60,14
```

---

## Step 10 — Audit evidence

Go to **Participants → Completion & certificate**. The **Compliance report** is there.

![The compliance report: Export CSV, Download all as JSON (ZIP), the filters, and one certificate with View, Download (JSON) and Revoke](img/mandatory-training-compliance-report.png)

- **The table** lists every issued certificate: Name, Passed on, Score (%), Valid until (with "Expired" where it applies), Verification ID.
  "The report shows issued certificates, not historical pass events."
- **Filters:** **Passed from**, **Passed until**, **Expiring within (days)**, then **Apply filter**. For example, enter 60 in "Expiring within (days)" to see who must renew soon.
- **Export CSV:** the list the table shows, with the filter applied. Column names and statuses are in the language of the person exporting, and the file is UTF-8 (with a byte-order mark, so spreadsheet programs show umlauts and accents correctly).
- **Copies of the certificates:**
  - **View** opens a person's certificate. Use **Print** there to file it as a PDF.
  - **Download (JSON)** gives the signed, machine-verifiable certificate file (Open Badges).
  - **Download all as JSON (ZIP)** packs the certificates of the list currently shown.

  Every view and download is written to the audit log.
- **Revoke** withdraws a certificate, after a confirmation. **This cannot be undone.** After that, its verification page shows "Certificate withdrawn".
- If someone passed but has no certificate (for example, the signing key is missing), a warning names them.

**Checking a certificate.** Anyone can scan the QR code or open the verification link on the certificate. The public page shows the issuer, the course, the course elements, the issue and expiry dates, and whether the certificate is valid, expired or withdrawn. For data protection, it does not show the holder's name. The verifier compares the Certificate ID on the document with the one on the page.

**For an external auditor.** The administrator creates a Nextcloud group named `learning-auditors`, which does not exist by default, and adds the auditor to it. To use another group, run `occ config:app:set learning auditor_group --value=<group id>`. Members of that group can open `/apps/learning/audit/export` on your Nextcloud. There they download a signed, tamper-evident event log (JSONL plus a .sig file) or open a printable report. The app has no menu link to this page, so send them the address.

**Team leads.** The app has a **Team Lead Dashboard**: missing or overdue members, upcoming expirations and a **Send reminder** button for one group. It appears on the Courses page for people assigned as team lead. Assigning a team lead in the app is **(coming)**. Until then, use **Export CSV** and send the file to the team leads. Don't add a team lead to the course as **Instructor** to work around this. An Instructor can change the whole course and never gets a certificate in it, so a team lead who has to do the training themselves could not be certified.

---

## Glossary

Terms you will meet in the app that this setup does not need, as of 5.12.0.

**Pool** — A set of questions. A pool belongs to the person who created it, not to a course, and can be added to several courses. Each course decides for itself what a pool counts for (Required pools, pool rules). Opened from the top **Pools** menu, a pool is a practice area of its own, and an exam there never counts for a certificate.

**Leitner** — Flashcard practice with five boxes. A correct answer moves a question up one box and it comes back later and later. A wrong answer sends it back to box 1. It does not affect the certificate, so you can switch it off per course (step 6).

**Smart Queue** — One list of all Leitner questions that are due for a learner, from all their pools, most urgent first. It belongs to Leitner: it stays empty for anyone who never started Leitner, and a pool leaves it for learners who reach that pool only through courses with Leitner switched off. It is not shown inside a course.

**Class register** — A member directory in the course's Participants area. Learners who press "Make visible in course" appear with their name, short bio and the topics they can help with, and members can give each other kudos. It has nothing to do with the certificate. Switch: **Class register** in Course rules.

**Learning profile** — An optional "about me" form each person fills in under their settings (role, goals, "I can help with..." / "I need help with...", Visibility: Private, Course or Public). Learning partner, the Class register and the AI chat (only if AI is on and the person agreed) use it. Nobody has to fill it in, and the training and the certificate don't depend on it.

**Learning partner** — Under Communication, learners see other members of the course who can help with a topic they need, or need help with a topic they know. It is based on the help topics in the learning profile. Switch: **Learning partner** in Course rules.

**Feed and Announcements** — **Announcements** (instructors only) is the form where you write a message to the course. Published announcements appear in the **Feed**, which every member sees, together with automatic entries such as "New question pool: …". Instructors edit or delete announcements in the Feed. An edited one is marked "edited".

**Questions from learners** — The instructors' inbox under Communication. Learners send questions with **Ask a question** (or through the VirtuProf help). Instructors answer with **Send answer**, and both sides get a notification. Only the learner and the course's instructors see a question.

**Arena, Duel, Gameshow, League, Adventure** — Optional games between course members. **Duel** is one against one, **Gameshow** a live quiz round for 2 to 5 players. Both appear in the **Arena** tab, which has no switch of its own. **League** is a season with a standings table. **Adventure** is a story mode (in German only) with questions built into the story. None of them counts for the certificate. With gamification off, League, Arena and Adventure are hidden for everyone, and new courses start with all of them off. They sit in the collapsed "Game modes" area of Course rules.

**VirtuProf** — The animated helper in the side panel. It offers ready-made help and tips without any AI. Its AI chat only works if an administrator has set up an AI provider and the learner has agreed to AI use. Each person can switch it off with **Virtual assistant** in their settings. Learners don't need it to ask the instructors a question (see Questions from learners).
