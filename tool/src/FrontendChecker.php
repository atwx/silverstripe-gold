<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 2 — Frontend/Config-Check (Konzept §6, Schritt 3).
 *
 * Vergleicht Build-Tool und deklarierte Node-Version gegen das Profil.
 * Abweichung / fehlende Deklaration → `misconfigured` (Tier 2).
 *
 * Die Node-Prüfung ist bewusst eine einfache Heuristik (Major-Vergleich) —
 * reicht für den Prototyp; ein echter Semver-Range-Solver ist nicht nötig.
 */
final class FrontendChecker
{
    /**
     * @param array<string,mixed> $profile
     * @return list<array<string,mixed>>
     */
    public function check(array $profile, string $projectDir): array
    {
        $frontend = $profile['frontend'] ?? null;
        if (!is_array($frontend)) {
            return [];
        }

        $items = [];
        $pkg = $this->readPackageJson($projectDir);

        // Build-Tool
        $expectedBuild = isset($frontend['build']) ? (string) $frontend['build'] : null;
        if ($expectedBuild !== null) {
            $actualBuild = $this->detectBuildTool($projectDir, $pkg);
            if ($actualBuild !== $expectedBuild) {
                $items[] = [
                    'name'     => 'frontend.build',
                    'status'   => 'misconfigured',
                    'expected' => $expectedBuild,
                    'actual'   => $actualBuild ?? 'unbekannt',
                    'tier'     => 2,
                ];
            }
        }

        // Node-Version
        $expectedNode = isset($frontend['node']) ? (string) $frontend['node'] : null;
        if ($expectedNode !== null) {
            $actualNode = $this->detectNodeVersion($projectDir, $pkg);
            if ($actualNode === null) {
                $items[] = [
                    'name'     => 'frontend.node',
                    'status'   => 'misconfigured',
                    'expected' => $expectedNode,
                    'tier'     => 2,
                    'note'     => 'Node-Version nicht deklariert (engines.node / .nvmrc)',
                ];
            } elseif (!$this->nodeSatisfies($actualNode, $expectedNode)) {
                $items[] = [
                    'name'     => 'frontend.node',
                    'status'   => 'misconfigured',
                    'expected' => $expectedNode,
                    'actual'   => $actualNode,
                    'tier'     => 2,
                ];
            }
        }

        return $items;
    }

    /** @return array<string,mixed>|null */
    private function readPackageJson(string $projectDir): ?array
    {
        $path = rtrim($projectDir, '/') . '/package.json';
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /** @param array<string,mixed>|null $pkg */
    private function detectBuildTool(string $projectDir, ?array $pkg): ?string
    {
        $deps = array_merge(
            (array) ($pkg['dependencies'] ?? []),
            (array) ($pkg['devDependencies'] ?? []),
        );

        if (is_file($projectDir . '/vite.config.js')
            || is_file($projectDir . '/vite.config.ts')
            || isset($deps['vite'])) {
            return 'vite';
        }
        if (is_file($projectDir . '/webpack.mix.js') || isset($deps['laravel-mix'])) {
            return 'webpack';
        }
        if (is_file($projectDir . '/webpack.config.js') || isset($deps['webpack'])) {
            return 'webpack';
        }

        return null;
    }

    /** @param array<string,mixed>|null $pkg */
    private function detectNodeVersion(string $projectDir, ?array $pkg): ?string
    {
        $engines = $pkg['engines']['node'] ?? null;
        if (is_string($engines) && $engines !== '') {
            return $engines;
        }

        $nvmrc = rtrim($projectDir, '/') . '/.nvmrc';
        if (is_file($nvmrc)) {
            $value = trim((string) file_get_contents($nvmrc));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Heuristik: vergleicht die Major-Versionen aus erwartetem und ist-Wert. */
    private function nodeSatisfies(string $actual, string $expected): bool
    {
        $expectedMajor = $this->firstInt($expected);
        $actualMajor = $this->firstInt($actual);
        if ($expectedMajor === null || $actualMajor === null) {
            return true; // nicht vergleichbar → kein Fehlalarm
        }

        return str_contains($expected, '>=')
            ? $actualMajor >= $expectedMajor
            : $actualMajor === $expectedMajor;
    }

    private function firstInt(string $value): ?int
    {
        return preg_match('/\d+/', $value, $m) ? (int) $m[0] : null;
    }
}
