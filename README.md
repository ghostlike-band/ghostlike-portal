# GHOSTLIKE Member-Portal

WordPress-Plugin für das interne Band-Portal unter `/portal`.

- **Übersicht**: nächster Termin mit Zusage, Album-Fortschritt, eigene offene Aufgaben, Verlauf
- **Kalender**: wöchentliche Probe (automatisch), „Probe fällt aus“, eigene Termine (Studio, Konzert, Sonstiges), Zu-/Absagen, persönlicher iCal-Abo-Link
- **Studio-Tool**: Songs × Arbeitsschritte, Status per Klick, gewichteter Fortschritt, Fokus pro Mitglied, Song-Details
- Pressepool und PR-Zentrale folgen

## Einrichten
1. Plugin hochladen und aktivieren
2. Für jedes Mitglied einen Benutzer mit Rolle **Bandmitglied** anlegen (Vorname ausfüllen!)
3. **Einstellungen → GHOSTLIKE Portal**: Probe-Tag/Zeit/Ort, Arbeitsschritte, Gewichte, Zuständige

## Updates veröffentlichen
1. Version in `ghostlike-portal.php` an **zwei** Stellen hochzählen (Kopf + `GLP_VERSION`)
2. `git tag v0.1.1 && git push --tags`
3. Die GitHub Action baut `ghostlike-portal.zip` → in WordPress **Dashboard → Aktualisierungen**
