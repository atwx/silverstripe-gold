<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 2 — Fork-Check (Konzept §6, Schritt 3).
 *
 * Prüft pro Fork-Eintrag des Profils die `source.url` aus composer.lock gegen
 * `expected_source`. Fängt den Fall "Fork-Override ist rausgeflogen, kommt jetzt
 * vom Upstream". Abweichung → `forbidden-source` (Tier 3, nie automatisch).
 *
 * Das Org-Prefix kommt ausschließlich aus den Profil-Daten (`expected_source`),
 * nie aus dem Code (Naht §12).
 */
final class ForkChecker
{
    /**
     * @param array<string,mixed>                                              $profile
     * @param array<string,array{version:string,source_url:string,dist_url:string}> $installed
     * @return list<array<string,mixed>>
     */
    public function check(array $profile, array $installed): array
    {
        $forks = $profile['modules']['forks'] ?? [];
        if (!is_array($forks)) {
            return [];
        }

        $items = [];
        foreach ($forks as $fork) {
            if (!is_array($fork) || empty($fork['name'])) {
                continue;
            }
            $name = (string) $fork['name'];
            $expected = (string) ($fork['expected_source'] ?? '');

            if (!isset($installed[$name])) {
                // Quelle eines fehlenden Pakets lässt sich nicht prüfen → missing.
                $items[] = [
                    'name'   => $name,
                    'status' => 'missing',
                    'tier'   => 2,
                    'note'   => 'erwarteter Fork nicht installiert',
                ];
                continue;
            }

            $actual = $installed[$name]['source_url'];
            if ($expected !== '' && !$this->matches($actual, $expected)) {
                $items[] = [
                    'name'            => $name,
                    'status'          => 'forbidden-source',
                    'current'         => $installed[$name]['version'],
                    'expected_source' => $expected,
                    'actual_source'   => $actual,
                    'tier'            => 3,
                    'note'            => 'Fork-Rebase nötig — Git-Operation, kein composer update (§8)',
                ];
            }
            // sonst: Quelle passt → ok, nicht gelistet.
        }

        return $items;
    }

    /** Substring-Match auf normalisierten URLs (protokoll-/.git-agnostisch). */
    private function matches(string $actual, string $expected): bool
    {
        return str_contains($this->normalize($actual), $this->normalize($expected));
    }

    private function normalize(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^[a-z]+://#', '', $url) ?? $url; // Protokoll weg
        $url = preg_replace('#^git@([^:]+):#', '$1/', $url) ?? $url; // SSH → host/path
        return preg_replace('#\.git$#', '', $url) ?? $url; // .git-Suffix weg
    }
}
