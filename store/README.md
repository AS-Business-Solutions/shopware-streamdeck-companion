# Shopware-Store-Assets & Einreichung

Alles, was für die Listung von `asbs/shopware-streamdeck` im Shopware Community Store
gebraucht wird. Die Store-Seiten-Konfiguration selbst liegt eine Ebene höher in
[`../.shopware-extension.yml`](../.shopware-extension.yml).

## Inhalt

| Pfad | Zweck |
|------|-------|
| `build-assets.mjs` | Erzeugt Icon + Vorschaubilder aus SVG-Vorlagen (sharp) |
| `package.json` | Tooling (nur `sharp`), getrennt vom Composer-Stack |
| `images/icon.png` | Store-Icon 256×256, vollflächig, ohne Transparenz |
| `images/{de,en}/01-hero.png …` | Vorschaubilder 1920×1080 (16:9), je Sprache |
| `description.{de-DE,en-GB}.html` | Ausführliche Store-Beschreibung |
| `installation.{de-DE,en-GB}.html` | Installationsanleitung |

Das Admin-Plugin-Icon (Erweiterungsliste) liegt unter
`../src/Resources/config/plugin.png` und wird vom selben Skript erzeugt.

## Assets neu erzeugen

Nur nötig, wenn sich Vorlagen/Texte in `build-assets.mjs` ändern:

```bash
cd store
npm install      # einmalig
npm run build    # rendert alle PNGs, prüft Maße & 1-MB-Limit
```

Markenpalette (aus `logo_rainbow.svg`): Violett `#570089`/`#8a17c7` → Rot `#ff0043` →
Gold `#ffd104`. Font: Liberation Sans (Inter-kompatible Metrik), serverseitig vorhanden.

## Store-Anforderungen (Stand der Umsetzung)

- Icon 256×256 (Store) / `plugin.png` (Admin) — vollflächig, kein Text. ✔
- Vorschaubilder 1920×1080, PNG, < 1 MB, 16:9, keine UI-/Promo-Nachahmung. ✔
- Beschreibungen DE + EN, `CHANGELOG_de-DE.md` + `CHANGELOG_en-GB.md`. ✔
- `composer.json` mit `description`, `keywords`, `license: MIT`, `extra.label/description`. ✔

## Einreichungs-Workflow (Hersteller-Account vorhanden)

Voraussetzung: [`shopware-cli`](https://github.com/shopware/shopware-cli) installiert.

```bash
# aus shopware-plugin/ (Plugin-Root)
shopware-cli account login                         # Hersteller-Account
shopware-cli extension validate .                  # Store-Konformität prüfen

# 1) Store-Seite (Texte, Bilder, FAQ, Kategorien) aus .shopware-extension.yml hochladen
shopware-cli account producer extension-info push .

# 2) Installierbares ZIP bauen (Produktions-Layout, ohne Dev-Dateien)
shopware-cli extension zip .

# 3) Binary/Version hochladen
shopware-cli account producer extension upload <erzeugtes.zip>
```

Danach wird die Version im Hersteller-Account zur automatischen + manuellen Prüfung
(PHPStan/SonarQube, Funktion, Sicherheit) eingereicht. Beschreibungen werden über die
`file:`-Referenzen in `.shopware-extension.yml` aus den HTML-Dateien gezogen.

> Hinweis: Die designten Vorschaubilder sind Marketing-Grafiken (keine Hardware-Fotos).
> Echte Fotos des Stream Decks mit Shop-Daten können die Listung später weiter aufwerten —
> einfach die PNGs in `images/{de,en}/` ersetzen (gleiche Maße) oder ergänzen.
