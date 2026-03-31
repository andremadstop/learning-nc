# Network+ Buchkapitel RAG-Import

Verifizierter DevCloud-Pfad:

```text
/var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel
```

Verifizierte Kurs-ID:

```text
20 = CompTIA Network+ (N10-009)
```

Dry-Run:

```bash
docker exec -u www-data learning-app php occ learning:import-vault \
  /var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel \
  --course-id=20 \
  --dry-run
```

Live-Import:

```bash
docker exec -u www-data learning-app php occ learning:import-vault \
  /var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel \
  --course-id=20
```

Stand 2026-03-31:

- 23 Markdown-Dateien gefunden
- 34 RAG-Chunks importiert
- erneuter Dry-Run meldet `Skipped existing: 23`
- DB-Verifikation fuer dieses Kapitelset: `34` Chunks mit `source_file LIKE 'Kapitel-%'`
