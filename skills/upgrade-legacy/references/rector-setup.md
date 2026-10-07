# Rector setup & run loop

## Install

```bash
composer require phpstan/extension-installer --dev
composer require cambis/silverstan --dev
composer require wernerkrauss/silverstripe-rector --dev
vendor/bin/rector init   # optional; we overwrite rector.php anyway
```

`phpstan.neon` (minimal):

```neon
parameters:
  level: 1
  paths:
    - app/src
```

## rector.php template (project upgrade)

```php
<?php

declare(strict_types=1);

use Netwerkstatt\SilverstripeRector\Set\SilverstripeLevelSetList;
use Netwerkstatt\SilverstripeRector\Set\SilverstripeSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app/_config.php',
        __DIR__ . '/app/src',
        // add mysite/code for legacy layouts, themes/*/src if PHP lives there,
        // and any ./module-to-update paths when upgrading modules in-place
    ])
    ->withPhpSets() // reads PHP version from composer.json — run this stage FIRST, alone
    ->withSets([
        SilverstripeSetList::CODE_STYLE,
        // enable ONE of these per stage, matching where you are in the hop chain:
        // SilverstripeLevelSetList::UP_TO_SS_4_13,
        // SilverstripeLevelSetList::UP_TO_SS_5_4,
        SilverstripeLevelSetList::UP_TO_SS_6_0,
        // SilverstripeLevelSetList::UP_TO_SS_6_2,
    ])
    ->withTypeCoverageLevel(0)   // raise incrementally AFTER the upgrade
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
```

Staging discipline — comment sets in/out rather than running all at once:

1. Only `->withPhpSets()` (Silverstripe sets commented out) → commit.
2. Only `CODE_STYLE` → commit.
3. Only `UP_TO_<current major latest>` → commit.
4. After the composer major hop: only `UP_TO_<target>` → commit.

## Run loop

```bash
git status                    # must be clean
vendor/bin/rector --dry-run   # review diff (add --debug to see files/rules)
vendor/bin/rector             # apply
vendor/bin/sake dev/build flush=1     # SS4/5   |   SS6: vendor/bin/sake db:build --flush
vendor/bin/phpunit            # if tests exist
git add -A && git commit -m "rector: <set name>"
```

If a Rector run produces bad changes: `git checkout -- .` and either exclude the rule
(`->withSkip([SomeRector::class])` or per-path) or fix manually.

## Quality levels afterwards

Once on the target version, raise gradually in separate commits:

```php
->withTypeCoverageLevel(10)  // then 20, 30, ...
->withDeadCodeLevel(5)
->withCodeQualityLevel(5)
```

And run PHPStan (level 4 is a realistic goal for a Silverstripe project with silverstan).
