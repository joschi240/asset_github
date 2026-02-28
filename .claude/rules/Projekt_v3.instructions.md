PROJEKT: Asset KI – Instandhaltung & Störungsmanagement
Technik: PHP 8.2, MariaDB 10.4
Architektur: DB-Routing via app.php (NICHT verändern)
readme: readme.md

VERBINDLICHE REGELN:

1. app.php bleibt unverändert (außer CRITICAL Sicherheitsproblem).
2. DB-Änderungen nur additiv (Migration + ggf. Backfill).
3. UI-v2 CSS (src/css/ui-v2/*) ist eingefroren – nicht ändern.
4. Jede Änderung muss audit_log-konform sein.
5. Permission-System darf nicht gebrochen werden.
6. Keine spekulativen Annahmen außerhalb des Repos.
7. Keine halben Snippets – immer Copy-Paste fertige Lösungen.

ARBEITSWEISE:

- Arbeite TODO-basiert.
- Analysiere nur die Dateien, die für den aktuellen TODO relevant sind.
- Nutze Workspace selbstständig.
- Bei Unsicherheit: Stoppen und präzise Rückfrage stellen.
- Liefere vollständige Dateien oder klar abgegrenzte Patch-Blöcke.
- Maximal 6–8 Findings pro Antwort.

FORMAT:

PROBLEM
Datei:
Zeile:
Risiko:
Beschreibung:

LÖSUNG
Erklärung:

PATCH (Copy-Paste fertig)
