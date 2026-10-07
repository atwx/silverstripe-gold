# Upgrading a third-party module yourself

When a required module has no release compatible with the target Silverstripe major and
no usable fork exists (check GitHub "Insights → Network" first!).

## 1. Fork & clone

Fork the module on GitHub, then clone it **inside the project root** for easy access:

```bash
git clone git@github.com:myhandle/module.git ./module-to-update
cd module-to-update
git checkout -b silverstripe6   # branch per target major
cd ..
```

## 2. Wire it into the project via a path repository

In the project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "./module-to-update" }
]
```

Then `composer require vendor/module:@dev` (composer symlinks the local checkout, so
edits are live).

## 3. Upgrade the module's code with Rector

Add the module path to the project's `rector.php`:

```php
->withPaths([
    __DIR__ . '/app/src',
    __DIR__ . '/module-to-update/src',
])
```

Run the same staged loop as for project code (dry-run → apply → test → commit inside the
module repo). Also update the module's own `composer.json`:

- core requirement, e.g. `"silverstripe/framework": "^6"`
- `"php": "^8.3"` for SS6

Check the module's YAML config, templates and JS for renamed classes (see
`ss5-to-ss6.md`), then push the branch to your fork.

## 4. Switch the project to consume your fork (for deployment)

Path repos don't deploy. Replace with a VCS repo pointing at your fork:

```json
"repositories": [
    { "type": "git", "url": "https://github.com/myhandle/module.git" }
]
```

```json
"require": {
    "vendor/module": "dev-silverstripe6 as 6.0"
}
```

- `dev-silverstripe6` = `dev-` + your branch name.
- `as 6.0` — inline alias: composer treats the branch as that version so other packages'
  constraints resolve. If your changes are breaking (renamed classes etc.), alias as a
  **new major** relative to the module's current release (module at 5.4 → alias `as 6.0`).

`composer update vendor/module` and verify.

## 5. Contribute back

Open a pull request from your fork's branch to the original repository so the ecosystem
gets the upgrade. Until it's merged/released, your project keeps running on the aliased
fork; once released, drop the repository entry and require the real version.
