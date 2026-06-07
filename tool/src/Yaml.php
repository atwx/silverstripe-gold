<?php

declare(strict_types=1);

namespace DriftChecker;

/**
 * Minimaler, abhängigkeitsfreier YAML-Parser für die Drift-Checker-Profile.
 *
 * Bewusst KEIN symfony/yaml: das Tool soll gegen beliebige Projekt-Repos laufen
 * (und später als self-contained PHAR ohne `composer install` verteilt werden,
 * Konzept §4/§13). Es parst nur unser eng umrissenes Profil-Schema:
 *
 *   - verschachtelte Maps via Einrückung
 *   - Block-Sequenzen (`- skalar` oder `- { flow map }`)
 *   - Inline-Flow-Maps `{ a: 1, b: 2 }` und Flow-Listen `[a, b]`
 *   - Skalare: quoted strings, int/float, bool, null, plain strings
 *   - `#`-Kommentare (am Zeilenanfang oder nach Whitespace, außerhalb Quotes)
 *
 * Listen-von-Maps werden in den Profilen absichtlich als Flow-Maps geschrieben
 * (`- { name: "x", freshness: latest-minor }`), damit der Parser keine
 * Block-Map-in-Sequenz auflösen muss.
 */
final class Yaml
{
    /** @return array<string,mixed> */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("YAML-Datei nicht lesbar: {$path}");
        }

        return self::parse((string) file_get_contents($path));
    }

    /** @return array<string,mixed> */
    public static function parse(string $content): array
    {
        $lines = self::tokenizeLines($content);
        $index = 0;
        $result = self::parseBlock($lines, $index, 0);

        return is_array($result) ? $result : [];
    }

    /**
     * Bereinigt Zeilen zu [indent, content]-Paaren; verwirft Leerzeilen und
     * reine Kommentarzeilen, strippt Inline-Kommentare.
     *
     * @return list<array{indent:int,content:string}>
     */
    private static function tokenizeLines(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $raw) {
            $stripped = self::stripComment($raw);
            if (trim($stripped) === '') {
                continue;
            }
            $indent = strlen($stripped) - strlen(ltrim($stripped, ' '));
            $out[] = ['indent' => $indent, 'content' => trim($stripped)];
        }

        return $out;
    }

    /** Entfernt einen Inline-Kommentar (#) außerhalb von Quotes. */
    private static function stripComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($ch === '#' && !$inSingle && !$inDouble) {
                // Kommentar nur am Zeilenanfang oder nach Whitespace.
                if ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t") {
                    return substr($line, 0, $i);
                }
            }
        }

        return $line;
    }

    /**
     * Parst einen Block (Map oder Sequenz) auf gegebener Mindest-Einrückung.
     *
     * @param list<array{indent:int,content:string}> $lines
     * @return array<string,mixed>|list<mixed>
     */
    private static function parseBlock(array $lines, int &$index, int $minIndent): array
    {
        $count = count($lines);
        if ($index >= $count) {
            return [];
        }

        $isSequence = str_starts_with($lines[$index]['content'], '- ')
            || $lines[$index]['content'] === '-';

        return $isSequence
            ? self::parseSequence($lines, $index, $lines[$index]['indent'])
            : self::parseMap($lines, $index, $lines[$index]['indent']);
    }

    /**
     * @param list<array{indent:int,content:string}> $lines
     * @return array<string,mixed>
     */
    private static function parseMap(array $lines, int &$index, int $indent): array
    {
        $map = [];
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            if ($line['indent'] < $indent) {
                break;
            }
            if ($line['indent'] > $indent) {
                // Defekte Einrückung — überspringen statt zu crashen.
                $index++;
                continue;
            }
            if (str_starts_with($line['content'], '- ')) {
                break; // Sequenz auf gleicher Ebene gehört nicht zur Map.
            }

            [$key, $rest] = self::splitKeyValue($line['content']);
            $index++;

            if ($rest !== '') {
                $map[$key] = self::parseScalar($rest);
                continue;
            }

            // Leerer Wert → verschachtelter Block auf tieferer Einrückung.
            if ($index < $count && $lines[$index]['indent'] > $indent) {
                $childIndent = $lines[$index]['indent'];
                $map[$key] = self::parseBlock($lines, $index, $childIndent);
            } else {
                $map[$key] = null;
            }
        }

        return $map;
    }

    /**
     * @param list<array{indent:int,content:string}> $lines
     * @return list<mixed>
     */
    private static function parseSequence(array $lines, int &$index, int $indent): array
    {
        $seq = [];
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            if ($line['indent'] !== $indent || !str_starts_with($line['content'], '-')) {
                break;
            }

            $item = trim(substr($line['content'], 1));
            $index++;
            // Profile nutzen ausschließlich skalare oder Flow-Map-Items.
            $seq[] = self::parseScalar($item);
        }

        return $seq;
    }

    /** @return array{0:string,1:string} key + (getrimmter) Rest hinter dem ":" */
    private static function splitKeyValue(string $content): array
    {
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ':' && $depth === 0
                    && ($i + 1 >= $len || $content[$i + 1] === ' ')) {
                    return [
                        self::unquote(trim(substr($content, 0, $i))),
                        trim(substr($content, $i + 1)),
                    ];
                }
            }
        }

        // Kein ": " gefunden — ganze Zeile ist der Key (Wert null).
        return [self::unquote($content), ''];
    }

    private static function parseScalar(string $value): mixed
    {
        $value = trim($value);

        if ($value === '' || $value === '~' || strtolower($value) === 'null') {
            return null;
        }
        if ($value[0] === '{') {
            return self::parseFlowMap($value);
        }
        if ($value[0] === '[') {
            return self::parseFlowList($value);
        }
        if (($value[0] === '"' || $value[0] === "'")) {
            return self::unquote($value);
        }

        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^-?\d*\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function parseFlowMap(string $value): array
    {
        $inner = trim(substr($value, 1, -1));
        $map = [];
        foreach (self::splitTopLevel($inner) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $rest] = self::splitKeyValue($pair);
            $map[$key] = self::parseScalar($rest);
        }

        return $map;
    }

    /** @return list<mixed> */
    private static function parseFlowList(string $value): array
    {
        $inner = trim(substr($value, 1, -1));
        $list = [];
        foreach (self::splitTopLevel($inner) as $item) {
            if ($item === '') {
                continue;
            }
            $list[] = self::parseScalar($item);
        }

        return $list;
    }

    /**
     * Splittet an Top-Level-Kommas (respektiert Quotes + verschachtelte {}/[]).
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $content): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $len = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $parts[] = trim($buffer);
                    $buffer = '';
                    continue;
                }
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if ($first === '"' && $last === '"') {
                return str_replace('\\"', '"', substr($value, 1, -1));
            }
            if ($first === "'" && $last === "'") {
                return str_replace("''", "'", substr($value, 1, -1));
            }
        }

        return $value;
    }
}
