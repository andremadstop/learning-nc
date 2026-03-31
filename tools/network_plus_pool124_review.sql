BEGIN;

UPDATE oc_learning_questions
SET question_type = 'multiple',
    updated_at = EXTRACT(EPOCH FROM NOW())::bigint
WHERE id IN (13188, 13198, 13240);

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('Malware-Infektion', 'Einsatz von Standard-Anmeldedaten') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'Deauthentifizierungsangriff' THEN 1
        WHEN 'Malware-Infektion' THEN 2
        WHEN 'Korrupte Firmware' THEN 3
        WHEN 'IP-Spoofing' THEN 4
        WHEN 'Rainbow Tables' THEN 5
        WHEN 'Einsatz von Standard-Anmeldedaten' THEN 6
        ELSE position
    END
WHERE question_id = 13188;

INSERT INTO oc_learning_answers (question_id, text, is_correct, position)
SELECT 13188, 'Rainbow Tables', false, 5
WHERE NOT EXISTS (
    SELECT 1
    FROM oc_learning_answers
    WHERE question_id = 13188
      AND text = 'Rainbow Tables'
);

INSERT INTO oc_learning_answers (question_id, text, is_correct, position)
SELECT 13188, 'Einsatz von Standard-Anmeldedaten', true, 6
WHERE NOT EXISTS (
    SELECT 1
    FROM oc_learning_answers
    WHERE question_id = 13188
      AND text = 'Einsatz von Standard-Anmeldedaten'
);

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('Malware-Infektion', 'Einsatz von Standard-Anmeldedaten') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'Deauthentifizierungsangriff' THEN 1
        WHEN 'Malware-Infektion' THEN 2
        WHEN 'Korrupte Firmware' THEN 3
        WHEN 'IP-Spoofing' THEN 4
        WHEN 'Rainbow Tables' THEN 5
        WHEN 'Einsatz von Standard-Anmeldedaten' THEN 6
        ELSE position
    END
WHERE question_id = 13188;

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('BIOS', 'Firmware') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'BIOS' THEN 1
        WHEN 'Treiber' THEN 2
        WHEN 'ServerOS' THEN 3
        WHEN 'Firmware' THEN 4
        WHEN 'HIDS' THEN 5
        ELSE position
    END
WHERE question_id = 13198;

INSERT INTO oc_learning_answers (question_id, text, is_correct, position)
SELECT 13198, 'HIDS', false, 5
WHERE NOT EXISTS (
    SELECT 1
    FROM oc_learning_answers
    WHERE question_id = 13198
      AND text = 'HIDS'
);

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('BIOS', 'Firmware') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'BIOS' THEN 1
        WHEN 'Treiber' THEN 2
        WHEN 'ServerOS' THEN 3
        WHEN 'Firmware' THEN 4
        WHEN 'HIDS' THEN 5
        ELSE position
    END
WHERE question_id = 13198;

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('Zwei-Faktor-Authentifizierung', 'Komplexe Passwörter') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'Ein Captives Portal' THEN 1
        WHEN 'Zwei-Faktor-Authentifizierung' THEN 2
        WHEN 'Geografische Blockaden (Geofencing)' THEN 3
        WHEN 'Komplexe Passwörter' THEN 4
        WHEN 'Die explizite Verweigerung (explicit deny)' THEN 5
        WHEN 'Einen rollenbasierten Zugriff' THEN 6
        ELSE position
    END
WHERE question_id = 13240;

INSERT INTO oc_learning_answers (question_id, text, is_correct, position)
SELECT 13240, 'Die explizite Verweigerung (explicit deny)', false, 5
WHERE NOT EXISTS (
    SELECT 1
    FROM oc_learning_answers
    WHERE question_id = 13240
      AND text = 'Die explizite Verweigerung (explicit deny)'
);

INSERT INTO oc_learning_answers (question_id, text, is_correct, position)
SELECT 13240, 'Einen rollenbasierten Zugriff', false, 6
WHERE NOT EXISTS (
    SELECT 1
    FROM oc_learning_answers
    WHERE question_id = 13240
      AND text = 'Einen rollenbasierten Zugriff'
);

UPDATE oc_learning_answers
SET is_correct = CASE
        WHEN text IN ('Zwei-Faktor-Authentifizierung', 'Komplexe Passwörter') THEN true
        ELSE false
    END,
    position = CASE text
        WHEN 'Ein Captives Portal' THEN 1
        WHEN 'Zwei-Faktor-Authentifizierung' THEN 2
        WHEN 'Geografische Blockaden (Geofencing)' THEN 3
        WHEN 'Komplexe Passwörter' THEN 4
        WHEN 'Die explizite Verweigerung (explicit deny)' THEN 5
        WHEN 'Einen rollenbasierten Zugriff' THEN 6
        ELSE position
    END
WHERE question_id = 13240;

UPDATE oc_learning_questions
SET updated_at = EXTRACT(EPOCH FROM NOW())::bigint
WHERE id IN (13188, 13198, 13240, 13245);

COMMIT;
