<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * SCHICHT 1-Zugriff: liest den Projekt-Zeiger (.gold-profile.yml) und merged
 * den effektiven Idealstand aus den lokalen Profilen (Konzept §5/§6, Schritt 2).
 *
 * Heute werden die Profile aus einem LOKALEN profiles/-Ordner gelesen.
 * Das Holen aus `gold_repo`@`gold_ref` über GitHub (Konzept §6 Schritt 2) kommt
 * später — die Naht dafür ist hier markiert (siehe loadProfile()).
 */
final class ProfileResolver
{
    public function __construct(
        private readonly string $profilesDir,
    ) {
    }

    /**
     * Liest den dünnen Projekt-Zeiger.
     *
     * @return array<string,mixed>
     */
    public function readPointer(string $projectDir): array
    {
        $path = rtrim($projectDir, '/') . '/.gold-profile.yml';
        if (!is_file($path)) {
            throw new \RuntimeException(
                ".gold-profile.yml nicht gefunden in {$projectDir} (Konzept §4)"
            );
        }

        $pointer = Yaml::parseFile($path);
        if (empty($pointer['profile'])) {
            throw new \RuntimeException('.gold-profile.yml: Feld `profile` fehlt.');
        }

        return $pointer;
    }

    /**
     * Merged base + <profile> + features zum effektiven Idealstand.
     *
     * @param array<string,mixed> $pointer
     * @return array<string,mixed>
     */
    public function resolve(array $pointer): array
    {
        $profileName = (string) $pointer['profile'];

        // 1. Profil laden und seine extends-Kette (base) auflösen.
        $effective = $this->loadWithExtends($profileName);

        // 2. Feature-Overlays mergen (Konzept §5: komponieren statt aufzählen).
        $features = is_array($pointer['features'] ?? null) ? $pointer['features'] : [];
        foreach ($features as $feature) {
            $overlay = $this->loadProfile('features/' . (string) $feature);
            $effective = $this->mergeDeep($effective, $overlay);
        }

        return $effective;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadWithExtends(string $name): array
    {
        $profile = $this->loadProfile($name);

        $parentName = $profile['extends'] ?? null;
        if (is_string($parentName) && $parentName !== '') {
            $parent = $this->loadWithExtends($parentName);
            $profile = $this->mergeDeep($parent, $profile);
        }
        unset($profile['extends']);

        return $profile;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadProfile(string $name): array
    {
        // NAHT (§6 Schritt 2): hier wird später aus gold_repo@gold_ref via
        // raw.githubusercontent.com / `gh api` geholt. Heute: lokaler Ordner.
        $path = rtrim($this->profilesDir, '/') . '/' . $name . '.yml';
        if (!is_file($path)) {
            throw new \RuntimeException("Profil nicht gefunden: {$path}");
        }

        return Yaml::parseFile($path);
    }

    /**
     * Tiefes Mergen: Maps rekursiv, Listen werden konkateniert (Overlays
     * fügen Module hinzu), Skalare des Overlays gewinnen.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    private function mergeDeep(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (
                isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
            ) {
                if (array_is_list($base[$key]) && array_is_list($value)) {
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    $base[$key] = $this->mergeDeep($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
