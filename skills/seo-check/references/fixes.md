# Fix-Leitfaden

Erprobte Lösungen für die Funde von `seo_check.py`, entstanden in fassisi
(SS 6, Fluent, atwx/sck, Elemental). Vor dem Übernehmen gegen das Projekt
abgleichen: Klassennamen, Template-Pfade und Fluent ja/nein.

Template-Overrides gehören nach `app/templates/` mit demselben relativen Pfad
wie im Modul, `app` hat Vorrang vor `vendor/`.

---

## robots.txt fehlt (404)

Statische Datei `public/robots.txt` (wird vom Webserver direkt ausgeliefert):

```
User-agent: *
Disallow: /admin/
Disallow: /Security/
Disallow: /dev/

Sitemap: https://<live-domain>/sitemap.xml
```

- Live-Domain mit/ohne www vorher per `curl -sI` prüfen.
- Gilt auch auf Staging. Staging ohne Basic-Auth sonst indexierbar: eigene
  Lösung (Basic-Auth oder umgebungsabhängige Route) vorschlagen.

## Sitemap fehlt / kein XML

```bash
ddev composer require wilr/silverstripe-googlesitemaps   # SS6: ^4, SS5: ^3
ddev exec vendor/bin/sake db:build --flush               # SS5: ddev sake dev/build flush=1
```

Mit Fluent bringt das Modul automatisch eine Teil-Sitemap pro Locale mit
`xhtml:link`-Alternates (`FluentSitemapExtension`). Weitere DataObjects mit
eigener Detailseite per `GoogleSitemap::register_dataobject()` registrieren.

Live: nach Deploy `db:build --flush`, sonst liefert `/sitemap.xml` die HTML-Seite.

## Dev-Modus / x-page-id / x-cms-edit-link

- `/dev` öffentlich erreichbar = `SS_ENVIRONMENT_TYPE` nicht `live`. In der
  `.env` auf dem Server korrigieren.
- `x-page-id` und `x-cms-edit-link` kommen aus `SiteTree::MetaComponents()`
  und nur für Benutzer mit `CMS_ACCESS_CMSMain`. Kunden sehen sie, wenn sie
  eingeloggt den Quelltext ansehen. Die CMS-Vorschau braucht sie, daher nur
  außerhalb der Vorschau entfernen:

```php
// Page.php
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;

/**
 * SiteTree adds x-page-id and x-cms-edit-link for CMS users. The CMS
 * only needs them inside its preview, so leave them out everywhere
 * else.
 */
public function MetaComponents()
{
    $tags = parent::MetaComponents();
    $request = Injector::inst()->has(HTTPRequest::class) ? Injector::inst()->get(HTTPRequest::class) : null;
    if (!$request || !$request->getVar('CMSPreview')) {
        unset($tags['pageId'], $tags['cmsEditLink']);
    }
    return $tags;
}
```

(`Controller::has_curr()` gibt es in SS6 nicht mehr.)

## Startseite / Seiten unter mehreren URLs (Fluent)

Typisch mit Fluent und `disable_default_prefix: false`:
`/`, `/home`, `/de`, `/de/home` liefern alle 200, und Unterseiten der
Default-Locale antworten auch ohne Präfix (`/produkte` neben `/de/produkte`).
Core-Redirect `/home → /` greift nicht, weil `RelativeLink()` das Locale-Präfix
enthält.

Entscheidung Hauptversion: Default `/de` (alle deutschen URLs liegen unter
`/de/…`), `/` leitet per 301 dorthin. Mit dem Kunden abstimmen, falls er `/`
als Startseite will (dann `disable_default_prefix: true` – ändert aber alle
deutschen URLs, Redirect-Listen beachten).

```php
// PageController.php
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\ErrorPage\ErrorPage;
use TractorCow\Fluent\Extension\FluentDirectorExtension;

protected function init()
{
    parent::init();
    if ($this->redirectedTo()) {
        return;
    }
    $this->redirectToCanonicalURL();
}

/**
 * Send URL aliases (unprefixed default locale, /home, /de/home, /) to the
 * page's own link with a 301 so search engines see one URL per page and
 * locale.
 */
protected function redirectToCanonicalURL(): void
{
    $request = $this->getRequest();
    $record = $this->dataRecord;
    // Error pages are rendered under the URL that failed.
    if (!$record instanceof SiteTree
        || $record instanceof ErrorPage
        || !$record->isInDB()
        || !$request->isGET()
        || $request->param('Action')
        || $request->getVar('CMSPreview')
        || $request->getVar('stage')
    ) {
        return;
    }

    // The request URL cannot be used here: RootURLController rewrites
    // it to "home" for the home page, whatever path was requested.
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = substr(rawurldecode($path), strlen(Director::baseURL()));
    if (trim($path, '/') === trim($record->RelativeLink() ?? '', '/')) {
        return;
    }

    $getVars = $request->getVars();
    unset($getVars['url'], $getVars[FluentDirectorExtension::config()->get('query_param')]);
    $link = $record->Link();
    if ($getVars) {
        $link .= '?' . http_build_query($getVars);
    }
    $this->redirect($link, 301);
}
```

Fallstricke, die beim Testen aufgefallen sind:
- `$request->getURL()` ist auf der Startseite immer `home` → Redirect-Schleife.
  Deshalb `REQUEST_URI`.
- ErrorPage ausnehmen, sonst bekommt jede 404 einen `Location`-Header.
- Ohne Fluent: `FluentDirectorExtension`-Zeile weglassen.

Testen:
```bash
for u in / /home /de /de/ /de/home /en/home /produkte /de/produkte "/home?x=1" /Security/login /de/gibtsnicht; do
  echo "$u $(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "https://<projekt>.ddev.site$u")"; done
```

## Canonical fehlt

```php
// Page.php
/**
 * Self-referencing canonical URL. Error pages and the temporary pages
 * used by the Security controller have none.
 */
public function CanonicalURL(): ?string
{
    if ($this instanceof ErrorPage || !$this->isInDB()) {
        return null;
    }
    return $this->AbsoluteLink();
}
```

```ss
<% if $CanonicalURL %>
    <link rel="canonical" href="$CanonicalURL.ATT"/>
<% end_if %>
```

Seiten mit eigenen Detail-Actions (z.B. `show/$ID`) brauchen eine eigene
`CanonicalURL()` im Controller, sonst zeigt das Canonical auf die Übersicht.

## hreflang mit Regionscodes (de-de, en-us) / x-default auf Weiterleitung

Fluent gibt `strtolower(i18n::convert_rfc1766($Locale))` aus. Override
`app/templates/FluentSiteTree_MetaTags.ss`:

```ss
<%-- Overrides the Fluent template: plain language codes (de, en, ...) instead
     of de-de, en-us, and x-default on every page, pointing to the default
     locale because / only redirects there. Pages without a canonical URL
     (error pages) get none. --%>
<% if $CanonicalURL && $Locales %><% loop $Locales %><% if $IsPublished && $canViewInLocale %>
    <link rel="alternate" hreflang="$Language.ATT" href="$AbsoluteLink.ATT" />
    <% if $LocaleObject.IsGlobalDefault %>
    <link rel="alternate" hreflang="x-default" href="$AbsoluteLink.ATT" />
    <% end_if %>
<% end_if %><% end_loop %><% end_if %>
```

Nur sinnvoll, wenn jede Sprache genau einmal vorkommt (nicht de_DE + de_AT).

Gleiches für die Sitemap: Fluent bringt
`templates/Wilr/GoogleSitemaps/Control/GoogleSitemapController_sitemap.ss` mit.
Nach `app/templates/Wilr/GoogleSitemaps/Control/` kopieren, `$HrefLang` →
`$Language`, den `$LinkToXDefault`-Block entfernen und x-default wie oben in
der Locale-Schleife ausgeben. Template-Kommentar erst **nach** der
`<?xml …?>`-Zeile, sonst ist das XML ungültig.

Sprachumschalter (`LangSwitch.ss` o.ä.): `hreflang="$LocaleRFC1766"` →
`hreflang="$Language" lang="$Language"`.

## Open Graph / Twitter mit Platzhaltern

Typisches Muster im SCK-Template: `og:description = $Title`,
`og:url = $Link` (relativ, auf der Startseite sogar `/de/home`),
`twitter:site = $Title`, leeres `og:image`.

```php
// Page.php
public function SocialDescription(): string
{
    return (string) ($this->MetaDescription ?: SiteConfig::current_site_config()->Tagline);
}

/**
 * Absolute image URL for Open Graph and Twitter cards: the social image
 * from the site settings, otherwise the page's first hero slide.
 */
public function SocialImageURL(): ?string
{
    $image = SiteConfig::current_site_config()->SocialImage();
    if (!$image->exists()) {
        $slide = $this->HeroSlides()->first();
        $image = $slide ? $slide->Image() : null;
    }
    if (!$image || !$image->exists()) {
        return null;
    }
    $resized = $image->getIsImage() ? $image->FocusFill(1200, 630) : null;
    return ($resized ?: $image)->getAbsoluteURL();
}
```

```ss
<meta name="twitter:card" content="<% if $SocialImageURL %>summary_large_image<% else %>summary<% end_if %>"/>
<meta name="twitter:title" content="{$Title.ATT} - {$SiteConfig.Title.ATT}"/>
<meta name="twitter:description" content="$SocialDescription.ATT"/>
<% if $SocialImageURL %><meta name="twitter:image" content="$SocialImageURL.ATT"/><% end_if %>

<meta property="og:type" content="website"/>
<meta property="og:title" content="{$Title.ATT} - {$SiteConfig.Title.ATT}"/>
<meta property="og:description" content="$SocialDescription.ATT"/>
<meta property="og:site_name" content="$SiteConfig.Title.ATT"/>
<% if $CanonicalURL %><meta property="og:url" content="$CanonicalURL.ATT"/><% end_if %>
<% if $SocialImageURL %><meta property="og:image" content="$SocialImageURL.ATT"/><% end_if %>
```

`twitter:site` nur mit echtem @Handle ausgeben, `twitter:url` ist kein
Standard-Tag. `HeroSlides` gibt es nur mit atwx/sck – sonst anderes Fallback-Bild.

## apple-touch-icon leer / SVG

`{$SiteConfig.AppleTouchIcon.Fit(180,180).Url}` ist leer, wenn dort ein SVG
hängt (kein Resize möglich). iOS kann kein SVG. PNGs aus dem Projekt nehmen:

```ss
<%-- iOS needs PNG touch icons; the SVG from the site settings is not used. --%>
<link rel="apple-touch-icon" sizes="120x120" href="$resourceURL('app/client/icons/apple_touch_icon_120.png')"/>
<link rel="apple-touch-icon" sizes="180x180" href="$resourceURL('app/client/icons/apple_touch_icon_180.png')"/>
```

Vorher ansehen, ob die PNGs das Kundenlogo zeigen und nicht noch das
SCK-Standardicon. Gleich mitprüfen: `public/site.webmanifest` (`name`,
`short_name` oft noch „Standard Silverstripe Template“).

## Hero-Bild mit loading="lazy"

Silverstripe rendert `$Image` standardmäßig mit `loading="lazy"`. Für das
erste Slide/Hero abschalten:

```ss
<%-- The first slide is the LCP image, so it must not be lazy loaded. --%>
<% if $IsFirst %>
    $Image.FocusFill(2000,500).LazyLoad(false)
<% else %>
    $Image.FocusFill(2000,500)
<% end_if %>
```

## Meta-Description fehlt

Inhaltlich, nicht technisch: Liste der betroffenen Seiten an den Kunden bzw.
s2hub/silverstripe-autotranslate / Redaktion. Optional Fallback im Template
(Tagline), aber Google bevorzugt seitenindividuelle Texte.
