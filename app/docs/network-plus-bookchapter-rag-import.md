# RAG import of book chapters — a worked example

A concrete run of `occ learning:import-vault`, kept as a reference for the command's shape and
its output. The paths and IDs below are from one specific instance; substitute your own.

The source directory is a folder of Markdown files readable by the web-server user — here a
Nextcloud group folder:

```text
/var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel
```

The target course, by ID:

```text
20 = CompTIA Network+ (N10-009)
```

Dry run — walks the directory and reports what it would import, without writing:

```bash
occ learning:import-vault \
  /var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel \
  --course-id=20 \
  --dry-run
```

The real import:

```bash
occ learning:import-vault \
  /var/www/html/data/__groupfolders/1/Netzwerk+/Buchkapitel \
  --course-id=20
```

Result of this run (2026-03-31):

- 23 Markdown files found
- 34 RAG chunks imported
- a second dry run reports `Skipped existing: 23`, so the import is idempotent
- verified in the database: `34` chunks matching `source_file LIKE 'Kapitel-%'`

> `occ` is invoked differently depending on your installation — `sudo -u www-data php occ …`
> for a package install, `docker exec -u www-data <container> php occ …` under Docker, or
> `sudo nextcloud.occ …` for the Snap.
