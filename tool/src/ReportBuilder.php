<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 2 — baut den JSON-Report nach Schema §6.
 *
 * Bindeglied zu Schicht 3 (Tier-Playbook) und org-weit aggregierbar (§9).
 * Heute nur die Freshness-Items; missing/forbidden-source/misconfigured
 * kommen mit den nächsten Checker-Schritten dazu.
 */
final class ReportBuilder
{
    /**
     * Mapping `latest-status` → [status, tier] nach Konzept §6.
     */
    private const STATUS_MAP = [
        'up-to-date'        => ['ok', null],
        'semver-safe-update' => ['outdated-minor', 1],
        'update-possible'   => ['outdated-major', 3],
    ];

    /**
     * @param array<string,mixed>      $pointer          gelesener .gold-profile.yml-Zeiger
     * @param array<string,mixed>      $effectiveProfile gemergter Idealstand (für späteren Abgleich)
     * @param list<array<string,mixed>> $outdated         `installed`-Einträge von composer outdated
     * @param list<string>             $directDeps       alle Direct-Dependency-Namen (für ok-Zähler)
     * @param list<array<string,mixed>> $extraItems       fertige Items aus Fork-/Module-/Frontend-Checks
     * @param string                   $composerCmd      verwendetes Composer-Binary (z.B. "ddev composer")
     * @return array<string,mixed>
     */
    public function build(
        string $repo,
        array $pointer,
        array $effectiveProfile,
        array $outdated,
        array $directDeps,
        array $extraItems,
        string $composerCmd,
        string $checkedAt,
    ): array {
        $requiredModules = $this->requiredModuleNames($effectiveProfile);

        // Freshness-Items aus composer outdated.
        $freshnessItems = [];
        foreach ($outdated as $pkg) {
            $latestStatus = (string) ($pkg['latest-status'] ?? '');
            [$status, $tier] = self::STATUS_MAP[$latestStatus] ?? ['ok', null];

            if ($status === 'ok') {
                continue; // erscheint normalerweise gar nicht in `outdated`
            }

            $name = (string) ($pkg['name'] ?? '');
            $item = [
                'name'    => $name,
                'status'  => $status,
                'current' => self::normalizeVersion((string) ($pkg['version'] ?? '')),
                'latest'  => self::normalizeVersion((string) ($pkg['latest'] ?? '')),
                'tier'    => $tier,
            ];

            if (in_array($name, $requiredModules, true)) {
                $item['profile_required'] = true;
            }
            if ($status === 'outdated-major') {
                $item['note'] = 'MAJOR — Migration prüfen (Changelog / UPGRADING.md)';
            }

            $freshnessItems[] = $item;
        }

        // Alle Findings zusammenführen; Tiers über die fertigen Items zählen.
        $items = array_merge($freshnessItems, $extraItems);
        $summary = ['ok' => 0, 'tier1' => 0, 'tier2' => 0, 'tier3' => 0];
        foreach ($items as $item) {
            match ($item['tier'] ?? null) {
                1 => $summary['tier1']++,
                2 => $summary['tier2']++,
                3 => $summary['tier3']++,
                default => null,
            };
        }

        // ok = aktuelle Direct-Dependencies (Direct-Deps minus geflaggte Freshness).
        $summary['ok'] = max(0, count($directDeps) - count($freshnessItems));

        return [
            'repo'       => $repo,
            'profile'    => $this->profileLabel($pointer),
            'gold_repo'  => $pointer['gold_repo'] ?? null,
            'gold_ref'   => $pointer['gold_ref'] ?? null,
            'composer'   => $composerCmd,
            'checked_at' => $checkedAt,
            'items'      => $items,
            'summary'    => $summary,
        ];
    }

    /**
     * "silverstripe+frontdesk-kit" aus profile + features.
     *
     * @param array<string,mixed> $pointer
     */
    private function profileLabel(array $pointer): string
    {
        $parts = [(string) $pointer['profile']];
        $features = is_array($pointer['features'] ?? null) ? $pointer['features'] : [];
        foreach ($features as $feature) {
            $parts[] = (string) $feature;
        }

        return implode('+', $parts);
    }

    /**
     * Sammelt alle required-Modulnamen aus modules.required des Idealstands.
     *
     * @param array<string,mixed> $profile
     * @return list<string>
     */
    private function requiredModuleNames(array $profile): array
    {
        $required = $profile['modules']['required'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        $names = [];
        foreach ($required as $module) {
            if (is_array($module) && !empty($module['name'])) {
                $names[] = (string) $module['name'];
            }
        }

        return $names;
    }

    private static function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
    }
}
