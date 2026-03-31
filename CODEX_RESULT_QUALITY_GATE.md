# Quality Gate — Ergebnis

Stand: 2026-03-31

## Gate 1

- PHPStan auf `learning-dev`: **OK**
  - Befehl: `ssh learning-dev 'docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && php vendor/bin/phpstan analyse --no-progress"'`
  - Ergebnis: `[OK] No errors`
- ESLint lokal: **OK mit Warnings**
  - Befehl: `cd app && npx eslint --ext .js,.vue src/`
  - Ergebnis: `0 errors`, `13 warnings`
- Vitest lokal: **OK**
  - Befehl: `cd app && npm run test`
  - Ergebnis: `55 passed`, `744 passed`

## Gefixte Findings

1. [packetParser.js](/home/andre/Workspace/Code/learning-nc/app/src/utils/packetParser.js)
   - `TTL exceeded` wurde nur in der alten englischen Detail-Form erkannt.
   - Fix: Erkennung jetzt robust über `ICMP type 11` plus englische und deutsche Detail-Texte.
2. [PrivacyInfo.test.js](/home/andre/Workspace/Code/learning-nc/app/tests/unit/PrivacyInfo.test.js)
   - Test erwartete ASCII-Altformen wie `Pruefung` und `Persoenlichkeitsprofil`.
   - Fix: Pattern auf aktuelle UTF-8-Namen erweitert.
3. [privacyInfoGroups.test.js](/home/andre/Workspace/Code/learning-nc/app/tests/unit/privacyInfoGroups.test.js)
   - Test erwartete veralteten ASCII-String `Persoenlichkeitsprofil (Telos)`.
   - Fix: auf den aktuellen Datenstand `Persönlichkeitsprofil (Telos)` umgestellt.

## ESLint-Warnings

- [AIGenerator.vue](/home/andre/Workspace/Code/learning-nc/app/src/components/AIGenerator.vue): ungenutztes `showError`
- [CourseTabVerwaltung.vue](/home/andre/Workspace/Code/learning-nc/app/src/components/CourseTabVerwaltung.vue): `vue/no-mutating-props` mehrfach
- [LernwuerfelMode.vue](/home/andre/Workspace/Code/learning-nc/app/src/components/LernwuerfelMode.vue): ungenutztes `SLOT_COLORS`
- [QuestionForm.vue](/home/andre/Workspace/Code/learning-nc/app/src/components/QuestionForm.vue): ungenutztes `axios`
- [cliStateMachine.js](/home/andre/Workspace/Code/learning-nc/app/src/utils/cliStateMachine.js): ungenutztes `ctx`
- [questMapEngine.js](/home/andre/Workspace/Code/learning-nc/app/src/utils/questMapEngine.js): ungenutztes `currentNodeId`
- [subnetExplainer.js](/home/andre/Workspace/Code/learning-nc/app/src/utils/subnetExplainer.js): ungenutztes `maskByte`

## Fazit

Gate 1 ist damit wieder grün:

- PHPStan: grün
- ESLint: grün im Sinne der Projektregel `0 Errors`, mit 13 bekannten Warnings
- Vitest: grün
