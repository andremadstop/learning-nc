# Requirements: Learning-NC v4.1

**Defined:** 2026-03-22
**Core Value:** VirtuProf beantwortet Fragen basierend auf echtem Kursmaterial, nicht nur Pool-Fragen.

## v4.1 Requirements

### Dokument-Management

- [x] **DOCS-01**: Dozent kann PDF/Markdown-Dateien in einen Kurs-Materialordner hochladen
- [x] **DOCS-02**: System extrahiert Text aus hochgeladenen PDFs via pdftotext
- [x] **DOCS-03**: System extrahiert Text aus Markdown-Dateien
- [x] **DOCS-04**: Dozent sieht Liste aller hochgeladenen Kursmaterialien mit Status

### Chunking

- [ ] **CHUNK-01**: System zerlegt extrahierten Text in ~500-Token-Chunks
- [ ] **CHUNK-02**: Chunks erhalten Kapitel-Tags aus Dokumentstruktur (Headings)
- [ ] **CHUNK-03**: Chunking läuft als BackgroundJob (nicht synchron)
- [ ] **CHUNK-04**: Chunks werden in learning_rag_chunks Tabelle gespeichert (course_id, chapter, text, source_file, created_at)

### Suche

- [ ] **SEARCH-01**: System findet relevante Chunks per Keyword-Match gegen User-Frage
- [ ] **SEARCH-02**: Suchergebnisse werden nach Relevanz sortiert (Treffer-Häufigkeit + Kapitel-Match)

### Multi-Source-RAG

- [ ] **RAG-01**: RagContextService bündelt: Pool-Fragen + User-Profil + Chat-Memory + Dokument-Chunks + User-Notes
- [ ] **RAG-02**: VirtuProf-Antworten enthalten Quellenangaben (z.B. "[Quelle: Dateiname, Kap. 6]")
- [ ] **RAG-03**: Context enthält User-Schwächen und vergangene Erklärungen
- [ ] **RAG-04**: Context-Fenster wird intelligent gefüllt (Priorität: relevante Chunks > Pool-Fragen > History)

## Future Requirements

### Vektor-Suche (Stufe 3)

- **VEC-01**: Gemini Embedding API (text-embedding-004) für Chunk-Embeddings
- **VEC-02**: Vektor-Similarity statt Keyword-Match
- **VEC-03**: Bessere Chunk-Relevanz durch semantische Suche

## Out of Scope

| Feature | Reason |
|---------|--------|
| Vektor-Embeddings | Stufe 3, späterer Milestone |
| OCR für gescannte PDFs | pdftotext reicht für digitale PDFs |
| Externe Dokumentquellen (URLs) | Nur NC-Dateien in v4.1 |
| DOCX/PPTX-Extraktion | Scope-Begrenzung, PDF+Markdown reichen |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| DOCS-01 | Phase 36 | Complete (36-01) |
| DOCS-02 | Phase 36 | Complete (36-01) |
| DOCS-03 | Phase 36 | Complete (36-01) |
| DOCS-04 | Phase 36 | Complete (36-01) |
| CHUNK-01 | Phase 37 | Pending |
| CHUNK-02 | Phase 37 | Pending |
| CHUNK-03 | Phase 37 | Pending |
| CHUNK-04 | Phase 37 | Pending |
| SEARCH-01 | Phase 38 | Pending |
| SEARCH-02 | Phase 38 | Pending |
| RAG-01 | Phase 39 | Pending |
| RAG-02 | Phase 39 | Pending |
| RAG-03 | Phase 39 | Pending |
| RAG-04 | Phase 39 | Pending |

**Coverage:**
- v4.1 requirements: 14 total
- Mapped to phases: 14
- Unmapped: 0

---
*Requirements defined: 2026-03-22*
*Last updated: 2026-03-22 after 36-01 completion*
