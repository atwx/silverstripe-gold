---
name: seo-check
description: Technical SEO audit for Silverstripe CMS projects running in ddev (with or without Fluent) — robots.txt, XML sitemap (wilr/silverstripe-googlesitemaps), dev mode and CMS meta tags, X-Robots-Tag, canonical tags, duplicate home page / unprefixed locale URLs, hreflang codes and reciprocity, Open Graph/Twitter placeholders, apple-touch-icons, lazy-loaded hero images — combined with a Lighthouse run (SEO, accessibility, best practices, performance) against ddev and the live site, plus a fix playbook. Use when the user wants to check a Silverstripe site's SEO, prepare it for Google Search Console, answer a customer's SEO/Search Console feedback, or run Lighthouse on a Silverstripe project.
---

# Silverstripe SEO-Check (+ Lighthouse)

Prüft ein Silverstripe-/ddev-Projekt auf die technischen SEO-Punkte, die bei
Search-Console-Reviews typischerweise auftauchen, und kombiniert das mit
Lighthouse. Ergebnis: priorisierter Bericht, danach optional Fixes nach
[references/fixes.md](references/fixes.md).

Ablauf: **1. Projekt erfassen → 2. Code-Check → 3. HTTP-Check (ddev + live)
→ 4. Lighthouse → 5. Bericht → 6. Fixes (nur nach Rückfrage)**

Chat auf Deutsch, Code-Kommentare auf Englisch, Frontend-Builds nur via
`ddev exec`.

---

## 1. Projekt erfassen

```bash
ddev describe | head -5                                   # lokale URL
grep -E '"silverstripe/(framework|recipe-cms)"' composer.lock -A2 | grep '"version"' | head -2
ddev composer show 2>/dev/null | grep -E 'fluent|googlesitemaps|atwx/sck|elemental|redirectedurls'
ddev mysql -e "select Locale, URLSegment, IsGlobalDefault from Fluent_Locale" 2>/dev/null
```

Live-Domain ermitteln (README, `public/robots.txt`, `.env`-Beispiele, sonst
den User fragen). Mit `curl -sI https://<domain>/` und `https://www.<domain>/`
klären, welche Variante die Hauptdomain ist.

Merken: SS-Version, Fluent ja/nein, Default-Locale, `disable_default_prefix`
(`grep -rn disable_default_prefix app/_config`), atwx/sck ja/nein.

## 2. Code-Check (statisch)

```bash
ls public/robots.txt
grep -rn "googlesitemaps" composer.json
grep -rln 'rel="canonical"' app/templates themes 2>/dev/null
grep -rn 'og:\|twitter:' app/templates themes 2>/dev/null | grep -E '\{?\$(Title|Link)\}?"' # placeholders
grep -rn 'apple-touch-icon' app/templates themes 2>/dev/null
ls app/templates/FluentSiteTree_MetaTags.ss app/templates/Wilr/GoogleSitemaps/Control/ 2>/dev/null
grep -rn 'LocaleRFC1766\|HrefLang' app/templates themes 2>/dev/null
grep -rn 'redirectToCanonicalURL\|MetaComponents' app/src
cat public/site.webmanifest 2>/dev/null | head -5
```

Wo ist der `<head>`? Bei atwx/sck: `app/templates/Atwx/Sck/Includes/SCKPage.ss`
(Override von `vendor/atwx/sck/templates/...`). Falls nur im Vendor vorhanden,
für Fixes erst nach `app/templates/` kopieren. Hero-Template: `grep -rln
"swiper-slide\|HeroSlides" app/templates vendor/atwx/sck/templates`.

## 3. HTTP-Check

`ss-seo-check` (liegt mit dem Plugin im PATH; Python 3, nur
Standardbibliothek, nur GET-Requests, auch gegen Live unbedenklich):

```bash
ss-seo-check https://<projekt>.ddev.site --pages 8
ss-seo-check https://<live-domain> --pages 8 --json <scratchpad>/seo-live.json
```

Ohne Plugin-PATH (z.B. bei Entwicklung am Repo): `bin/ss-seo-check` im
silverstripe-gold-Repo.

`--pages` = Anzahl zufälliger Sitemap-URLs (`--seed` ändern für andere
Stichprobe). TLS-Prüfung ist für `*.ddev.site` automatisch aus. Exit 1 bei ERROR.

Was geprüft wird:

| Bereich | Prüfung |
|---|---|
| robots.txt | 200, text/plain, `Sitemap:`-Zeile, kein `Disallow: /` |
| sitemap | XML, Index + Teil-Sitemaps, Host, Duplikate, hreflang-Werte |
| environment | `/dev` öffentlich = Dev-Modus (auf ddev nur INFO); `x-page-id`/`x-cms-edit-link` für anonyme Besucher |
| indexing | `X-Robots-Tag: noindex`, `<meta name="robots" content="noindex">` |
| host | http→https 301, www/non-www 301 (nur live) |
| home | `/`, `/home`, `/<locale>`, `/<locale>/`, `/<locale>/home`: pro Sprache genau eine 200-URL, 301 statt 302 |
| duplicates | Default-Locale-Seiten ohne Präfix, Trailing-Slash-Varianten liefern 200 |
| canonical | vorhanden, genau einer, absolut, selbstreferenzierend |
| hreflang | gültige Codes, Regionscodes wenn unnötig, x-default, absolut, Selbstreferenz; auf Home + 1 Seite: Ziele direkt 200 und Rückverweis |
| social | og:title/description/url/image, twitter:card vorhanden, nicht leer, absolut; og:description = Titel; twitter:site ohne @ |
| icons | apple-touch-icon leer oder SVG; leere icon/mask-icon |
| performance | erstes großes Bild (width ≥ 800) mit `loading="lazy"` |
| head | `<title>`, meta description, `<html lang>` |

Grenzen: Meta-Tags für eingeloggte CMS-User sieht das Skript nicht (anonym);
die Prüfung im eingeloggten Zustand ggf. im Browser. Ob live wirklich
`SS_ENVIRONMENT_TYPE=live` gesetzt ist, zeigt nur indirekt der `/dev`-Check.

## 4. Lighthouse

Über das **Chrome DevTools MCP** (`mcp__plugin_chrome-devtools-mcp_chrome-devtools__*`,
per ToolSearch laden: `new_page`, `lighthouse_audit`, `performance_start_trace`,
`performance_stop_trace`, `performance_analyze_insight`, `close_page`).

Seiten: Startseite + je eine Seite pro wichtigem Seitentyp (z.B. Produktdetail,
Übersicht mit Filter, Kontakt). Bevorzugt **gegen Live** – ddev läuft im
Dev-Modus ohne Caching, Performance-Werte sind dort nicht aussagekräftig.
ddev nutzen, um Fixes vor dem Deploy zu prüfen.

1. `new_page` mit `url`, `isolatedContext: "seo"` (frisches Profil, nicht
   eingeloggt, kein Cookie-Consent gespeichert) und `background: true`.
2. `lighthouse_audit` mit `device: "mobile"`, dann `"desktop"`. Pro Lauf ein
   **eigenes** `outputDirPath` (z.B. `<scratchpad>/lighthouse/home-mobile`),
   sonst wird `report.json` überschrieben. Liefert Accessibility, Best
   Practices, SEO und Agentic Browsing (keine Performance).
3. Fehlgeschlagene Audits mit betroffenen Elementen auslesen:
   ```bash
   ss-lighthouse-failed <scratchpad>/lighthouse/*/report.json
   ```
4. Performance: `performance_start_trace` mit `reload: true, autoStop: true`.
   Läuft ohne Throttling – für realistische Mobile-Werte vorher `emulate` mit
   Netzwerk „Slow 4G“ und CPU-Throttling 4× setzen. LCP, CLS und die Insights
   (`LCPDiscovery` → lazy Hero, `RenderBlocking`, `ThirdParties`, `Cache`) bei
   Bedarf mit `performance_analyze_insight` vertiefen.
5. `close_page`.

Alternative ohne MCP (Host, kein Projekt-Build – daher außerhalb von ddev ok):

```bash
npx -y lighthouse https://<live-domain>/de --output=json --output=html \
  --output-path=<scratchpad>/lh-home --chrome-flags="--headless=new" \
  --only-categories=performance,seo,accessibility,best-practices --form-factor=mobile
# gegen ddev zusätzlich: --chrome-flags="--headless=new --ignore-certificate-errors"
```

Lighthouse-Funde, die bei Silverstripe/SCK oft auftauchen: fehlende `alt`-Texte
aus dem Asset-Titel, Kontrast der SiteConfig-Farben, Links ohne erkennbaren
Text (Icon-Links, Sprachflaggen), Cookiebot/Analytics als Third-Party-Kosten,
Bilder ohne `width`/`height`.

## 5. Bericht

Zusammenführen und nach Priorität ordnen:

1. **Indexierung blockiert** (noindex, robots.txt-Sperre, Dev-Modus live)
2. **Duplikate / Canonical / Weiterleitungen**
3. **Sitemap / robots.txt / hreflang**
4. **Lighthouse SEO < 100** – einzelne Audits
5. **Head-Details** (OG/Twitter, Icons, Manifest) und **Performance** (Lazy-Hero, LCP)
6. **Inhaltlich** (fehlende Meta-Descriptions, alt-Texte) → Liste für Kunde/Redaktion

Pro Punkt: Befund, betroffene URL(s), Fix-Verweis auf `references/fixes.md`.
Lighthouse-Scores als kleine Tabelle (Seite × mobile/desktop × Kategorie).
Unterscheiden zwischen „auf Live so“ und „nur lokal“.

Bei Kundenrückmeldungen: Jeden Kundenpunkt explizit beantworten, auch wenn
er sich als Fehlinterpretation herausstellt (z.B. `x-page-id` nur sichtbar,
weil der Kunde eingeloggt war).

## 6. Fixes

Erst nach Rückfrage. Vorgehen je Fund in [references/fixes.md](references/fixes.md).
Nach jedem Fix:

```bash
curl -sk -o /dev/null "https://<projekt>.ddev.site/?flush=1"
ss-seo-check https://<projekt>.ddev.site
ddev exec vendor/bin/phpstan analyse <geänderte Dateien> --no-progress   # falls vorhanden
```

Zusätzlich alle Sitemap-URLs auf direkte 200 prüfen (keine Redirects in der
Sitemap):

```bash
for l in $(curl -sk https://<projekt>.ddev.site/sitemap.xml | grep -o '<loc>[^<]*' | sed 's/<loc>//'); do
  curl -sk "$l" | grep -o '<loc>[^<]*' | sed 's/<loc>//'; done \
  | xargs -P8 -n1 curl -sk -o /dev/null -w '%{http_code}\n' | sort | uniq -c
```

Nach dem Deploy: `db:build --flush` auf Live, dann HTTP-Check und Lighthouse
gegen Live wiederholen. Sitemap erst danach in der Search Console einreichen.
