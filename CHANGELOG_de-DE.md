# 0.6.0
- Neu: Endpunkt „Umsatz pro Monat" (aktuelles Jahr, Januar → aktueller Monat) für die Jahresansicht des Stream-Deck-Dials; gruppiert nach der konfigurierten Zeitzone (zeitzonensicher inkl. Sommer-/Winterzeit) und wendet dieselben Umsatz-Ausschlüsse sowie Netto/Brutto-Logik an wie die übrigen Kennzahlen

# 0.5.0
- Neu: Konfigurierbare Umsatz-Ausschlüsse – Positionen, die kein echter Umsatz sind (z. B. virtuelle Zuschläge), lassen sich per Produktauswahl oder Label-Liste aus allen Umsatzkennzahlen herausrechnen; die Bestellung selbst zählt weiterhin
- Geändert: Alle Umsatz-Endpunkte liefern jetzt immer Netto- und Brutto-Werte gleichzeitig (der Parameter „mode" entfällt)
- Behoben: Die Umsatz-Charts (pro Tag/Stunde) gruppieren jetzt nach der konfigurierten Zeitzone statt nach UTC – Umsatz aus den frühen Morgenstunden landet im richtigen Tag bzw. in der richtigen Stunde (zeitzonensicher inkl. Sommer-/Winterzeit)

# 0.4.1
- Behoben: Bestellungen aus den frühen Morgenstunden (lokale Zeit) wurden nicht in die Tagesauswertung (Umsatz und Anzahl) einbezogen – die Tagesgrenze wird jetzt korrekt in der konfigurierten Zeitzone berechnet

# 0.4.0
- Behoben: Die Plugin-Konfiguration wird jetzt im Admin angezeigt (Menüpunkt „Konfigurieren")
- Behoben: Die API-Key-Verwaltung lädt jetzt korrekt, statt im Ladekreis hängen zu bleiben

# 0.3.0
- Erstveröffentlichung im Shopware Store
- API-Key-Verwaltung direkt in der Plugin-Konfiguration (Generieren, Auflisten, Widerrufen) – kein Zugriff auf die Konsole nötig
- Metrik-Endpoints für das Stream Deck Plugin: Tagesumsatz, letzte Bestellung, Top-Bestellung & Top-Produkt, durchschnittlicher Bestellwert, Umsatzverlauf (7–60 Tage), Umsatz pro Stunde
- Live-Shop-Status: Produktivmodus, Systemzustand und überfällige geplante Aufgaben
- Konfigurierbare Bestell- und Zahlungsstatus-Filter für die Umsatzauswertung
- Authentifizierung über shop-gebundenen API-Key (SHA-256), kein externer Lizenzserver
- Kompilierte Administrations-Assets mitgeliefert: sofort einsatzbereit nach der Installation, ohne zusätzlichen Build-Schritt
