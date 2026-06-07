<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 2 — Freshness-Auge (Konzept §6, Schritt 3 "erst Freshness").
 *
 * Delegiert die schwere Arbeit an den projekteigenen Composer und parst nur
 * dessen JSON-Output. `composer outdated -D -f json` liefert pro Direct-
 * Dependency das `latest-status`-Feld, das wir auf Severities mappen.
 *
 * Fork-Check (composer.lock source.url) und Frontend/Config folgen als
 * nächste Schritte von §6 — hier bewusst noch nicht enthalten.
 */
final class ComposerInspector
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $composerBin = 'composer',
    ) {
    }

    /**
     * Wählt das passende Composer-Binary fürs Projekt.
     *
     * DDEV-Projekte (`.ddev/config.yaml` vorhanden) führen Composer im Container
     * aus — host-seitiges `composer` würde mit falscher PHP-Version / fehlenden
     * Plattform-Requirements laufen. Darum hier zuerst auf DDEV prüfen.
     * Ein explizites `--composer=...` übersteuert das (Entry-Logik).
     */
    public static function detectComposerBinary(string $projectDir): string
    {
        if (is_file(rtrim($projectDir, '/') . '/.ddev/config.yaml')) {
            return 'ddev composer';
        }

        return 'composer';
    }

    /**
     * Ruft `<composer> outdated -D -f json` im Projektverzeichnis auf.
     *
     * Hinweis: `outdated` listet nur Pakete MIT verfügbarem Update; voll
     * aktuelle Pakete erscheinen nicht (sie zählen als `ok`, siehe ReportBuilder).
     *
     * @return list<array<string,mixed>> die `installed`-Einträge
     */
    public function outdatedDirect(): array
    {
        // composerBin ist ein (vertrauenswürdiges) Kommando-Präfix und kann
        // Argumente enthalten (z.B. "ddev composer") — daher NICHT escapen.
        // Statt --working-dir ins Projekt wechseln: das funktioniert sowohl für
        // host-`composer` als auch für `ddev composer` (das den Projektkontext
        // aus dem CWD ermittelt).
        $cmd = sprintf(
            'cd %s && %s outdated -D -f json --no-interaction 2>/dev/null',
            escapeshellarg($this->projectDir),
            $this->composerBin,
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "`composer outdated` schlug fehl (Exit {$exitCode}) in {$this->projectDir}."
            );
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (!is_array($decoded) || !isset($decoded['installed']) || !is_array($decoded['installed'])) {
            throw new \RuntimeException('Konnte JSON von `composer outdated` nicht parsen.');
        }

        return $decoded['installed'];
    }

    /**
     * Liest die Direct-Dependencies aus composer.json (require + require-dev),
     * ohne `php` und `ext-*`. Dient dem `ok`-Zähler im Summary.
     *
     * @return list<string>
     */
    public function directDependencyNames(): array
    {
        $path = rtrim($this->projectDir, '/') . '/composer.json';
        if (!is_file($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }

        $names = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys($json[$section] ?? []) as $name) {
                $name = (string) $name;
                if ($name === 'php' || str_starts_with($name, 'ext-')) {
                    continue;
                }
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Liest die installierten Pakete aus composer.lock (packages + packages-dev).
     * Basis für Fork-Check (source.url) und Missing-Module-Check (Konzept §6).
     *
     * @return array<string,array{version:string,source_url:string,dist_url:string}>
     */
    public function installedPackages(): array
    {
        $path = rtrim($this->projectDir, '/') . '/composer.lock';
        if (!is_file($path)) {
            throw new \RuntimeException("composer.lock nicht gefunden in {$this->projectDir}.");
        }

        $lock = json_decode((string) file_get_contents($path), true);
        if (!is_array($lock)) {
            throw new \RuntimeException('Konnte composer.lock nicht parsen.');
        }

        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            if (empty($pkg['name'])) {
                continue;
            }
            $installed[(string) $pkg['name']] = [
                'version'    => (string) ($pkg['version'] ?? ''),
                'source_url' => (string) ($pkg['source']['url'] ?? ''),
                'dist_url'   => (string) ($pkg['dist']['url'] ?? ''),
            ];
        }

        return $installed;
    }

    public function projectName(): string
    {
        $path = rtrim($this->projectDir, '/') . '/composer.json';
        if (is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json) && !empty($json['name'])) {
                return (string) $json['name'];
            }
        }

        return basename(rtrim($this->projectDir, '/'));
    }
}
