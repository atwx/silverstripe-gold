<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 2 — Required-Module-Präsenz (Konzept §6: "Module vorhanden?").
 *
 * Required-Module aus dem effektiven Idealstand, die nicht installiert sind
 * → `missing` (Tier 2: vorschlagen + freigeben).
 */
final class ModuleChecker
{
    /**
     * @param array<string,mixed>                                              $profile
     * @param array<string,array{version:string,source_url:string,dist_url:string}> $installed
     * @return list<array<string,mixed>>
     */
    public function check(array $profile, array $installed): array
    {
        $required = $profile['modules']['required'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        $items = [];
        foreach ($required as $module) {
            if (!is_array($module) || empty($module['name'])) {
                continue;
            }
            $name = (string) $module['name'];
            if (!isset($installed[$name])) {
                $items[] = [
                    'name'   => $name,
                    'status' => 'missing',
                    'tier'   => 2,
                ];
            }
            // sonst: vorhanden → Freshness deckt das Update-Thema ab.
        }

        return $items;
    }
}
