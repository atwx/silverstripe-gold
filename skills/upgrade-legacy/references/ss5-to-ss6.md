# Silverstripe 5 → 6: manual checklist & rename tables

Prerequisite: project on 5.4, `UP_TO_SS_5_4` Rector run done, modules audited.
Full changelog: https://docs.silverstripe.org/en/6/changelogs/6.0.0/

## Environment

- **PHP ≥ 8.3** required (CLI and web).
- `dev/build` CLI is now `vendor/bin/sake db:build --flush`; dev tasks are Symfony console commands (`vendor/bin/sake tasks` lists them).

## Class renames (Rector fixes PHP — YOU must fix YAML, `.ss` templates, `_config.php` strings, JS)

Grep every occurrence of the old FQCNs in `app/_config/*.yml`, `themes/`, `app/templates/`, `.env`, and string literals. Key renames:

| Old | New |
|---|---|
| `SilverStripe\ORM\ArrayList` | `SilverStripe\Model\List\ArrayList` |
| `SilverStripe\ORM\GroupedList` | `SilverStripe\Model\List\GroupedList` |
| `SilverStripe\ORM\PaginatedList` | `SilverStripe\Model\List\PaginatedList` |
| `SilverStripe\ORM\ListDecorator` | `SilverStripe\Model\List\ListDecorator` |
| `SilverStripe\ORM\Map` | `SilverStripe\Model\List\Map` |
| `SilverStripe\ORM\SS_List` | `SilverStripe\Model\List\SS_List` |
| `SilverStripe\ORM\Filterable` / `Limitable` / `Sortable` | `SilverStripe\Model\List\Filterable` / `Limitable` / `Sortable` |
| `SilverStripe\ORM\ArrayLib` | `SilverStripe\Core\ArrayLib` |
| `SilverStripe\ORM\ValidationException` | `SilverStripe\Core\Validation\ValidationException` |
| `SilverStripe\ORM\ValidationResult` | `SilverStripe\Core\Validation\ValidationResult` |
| `SilverStripe\View\ViewableData` | `SilverStripe\Model\ModelData` |
| `SilverStripe\View\ViewableData_Customised` | `SilverStripe\Model\ModelDataCustomised` |
| `SilverStripe\View\ArrayData` | `SilverStripe\Model\ArrayData` |
| `SilverStripe\View\SSViewer_Scope` / `SSViewer_DataPresenter` | `SilverStripe\TemplateEngine\ScopeManager` |
| `SilverStripe\View\SSTemplateParser` (+ related) | `SilverStripe\TemplateEngine\...` |
| `SilverStripe\Forms\Validator` | `SilverStripe\Forms\Validation\Validator` |
| `SilverStripe\Forms\RequiredFields` | `SilverStripe\Forms\Validation\RequiredFieldsValidator` |
| `SilverStripe\Forms\CompositeValidator` | `SilverStripe\Forms\Validation\CompositeValidator` |
| `SilverStripe\Security\PasswordValidator` | `SilverStripe\Security\Validation\RulesPasswordValidator` |
| `SilverStripe\Forms\HTMLEditor\TinyMCEConfig` (+ related) | `SilverStripe\TinyMCE\TinyMCEConfig` |
| `SilverStripe\Logging\HTTPOutputHandler` | `SilverStripe\Logging\ErrorOutputHandler` |
| `SilverStripe\CMS\Model\CurrentPageIdentifier` | `SilverStripe\CMS\Model\CurrentRecordIdentifier` |
| `SilverStripe\CMS\Controllers\LeftAndMainPageIconsExtension` | `...\LeftAndMainRecordIconsExtension` |
| `DNADesign\Elemental\TopPage\*` | `DNADesign\Elemental\Extensions\TopPage*Extension` |
| `SilverStripe\UserForms\Form\UserFormsRequiredFields` | `...\UserFormsRequiredFieldsValidator` |
| `Symbiote\AdvancedWorkflow\Forms\AWRequiredFields` | `...\AWRequiredFieldsValidator` |
| `SilverStripe\ExternalLinks\*`, `SilverStripe\SecurityReport\*`, `SilverStripe\SiteWideContentReport\*` | merged under `SilverStripe\Reports\...` |

### Extension base classes removed

`SilverStripe\ORM\DataExtension`, `SilverStripe\CMS\Model\SiteTreeExtension`,
`SilverStripe\Admin\LeftAndMainExtension` → all become **`SilverStripe\Core\Extension`**
(with generics: `extends Extension` works everywhere). Rector rewrites the PHP; YAML
`extensions:` blocks referencing your own extension classes are unaffected, but any YAML
referencing the removed core classes must be updated.

## Extension hook renames (Rector cannot always detect these — grep your Extensions)

| Old hook | New hook |
|---|---|
| `init` | `onInit` |
| `beforeMemberLoggedIn` / `afterMemberLoggedIn` | `onBeforeMemberLoggedIn` / `onAfterMemberLoggedIn` |
| `beforeMemberLoggedOut` / `afterMemberLoggedOut` | `onBeforeMemberLoggedOut` / `onAfterMemberLoggedOut` |
| `authenticationFailed` / `authenticationFailedUnknownUser` / `authenticationSucceeded` | `onAuthenticationFailed` / `onAuthenticationFailedUnknownUser` / `onAuthenticationSucceeded` |
| `registerFailedLogin` | `onRegisterFailedLogin` |
| `forgotPassword` | `onForgotPassword` |
| `flushCache` | `onFlushCache` |
| `populateDefaults` | `onAfterPopulateDefaults` |
| `requireDefaultRecords` | `onRequireDefaultRecords` |
| `getDefaultRecords` | `updateDefaultRecords` |
| `MetaTags` / `MetaComponents` | `updateMetaTags` / `updateMetaComponents` |
| `validate` | `updateValidate` |

## BuildTasks — biggest manual job

Tasks are now Symfony console commands. `BuildTaskUpdateRector` converts the skeleton, but **you must design input parameters yourself** (`$request->getVar()` has no automatic equivalent). Find affected files: `grep -rln "extends BuildTask" app/src`.

Field-tested traps:
- `protected string $title` is **non-static**, `protected static string $description` is **static** — declaring both static causes `Cannot redeclare non static ... as static` fatals.
- Add `private static string $segment = 'my-task';` / `$commandName` — required for CLI invocation.
- Rector sometimes leaves the old `run($request, ...)` signature behind → "contains abstract method BuildTask::execute". The method must be `execute()`.
- `echo`/`print` → `$output->writeln()`; must `return Command::SUCCESS;` (or `0`).
- `cli-script.php` is **gone** — run via `vendor/bin/sake <segment>` / `vendor/bin/sake tasks:my-task`; `dev/tasks/my-task?param=x` still works over HTTP.

Pattern:

```php
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class MyTask extends BuildTask
{
    protected static string $commandName = 'my-task';
    protected string $title = 'My Task';

    public function getOptions(): array
    {
        return [
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Max records', 100),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $limit = (int) $input->getOption('limit');
        $output->writeln("Processing {$limit} records");
        return Command::SUCCESS;
    }
}
```

## Other API changes to check

- `DataObject::get_by_id()` removed → `MyClass::get()->byID($id)` (Rector: CODE_STYLE / SS_6_1 sets).
- `SSViewer::flush()` → `SSTemplateEngine::flush()`; `DBEnum::flushCache()` → `DBEnum::reset()`.
- `LeftAndMain::$tree_class` → `$model_class`.
- Form validation architecture reworked (validators moved to `Forms\Validation`, `RequiredFieldsValidator`); custom validators need re-checking.
- `Deprecation` handling comments removed by `RemoveSilverstripeDeprecationCommentRector`.
- Template layer rewritten (`SilverStripe\TemplateEngine`); custom template parser integrations need porting.

## 6.1 / 6.2 minors (run `UP_TO_SS_6_2` when targeting latest)

- 6.1: `DataObject::get_by_id/get_one/delete_by_id($class, …)` → fluent `DataObject::get($class)->setUseCache(true)->…` (`DataObjectStaticMethodsToFluentRector`).
- 6.2: `FieldList::dataFields()` → `getDataFields()`; `getIDList()` → `column('ID')` pattern.

## Runtime gotchas found in real upgrades

- **Templates: loop iterators renamed** — `$First` → `$IsFirst`, `$Last` → `$IsLast` (`$Pos`, `$Even`, `$Odd`, `$Middle` stay). Rector never touches `.ss` files. Symptom: `<% if $First %>` blocks silently never fire. Find: `grep -rln '\$First\|\$Last' app/templates/ themes/` and fix.
- **Rector over-tightens string typehints.** `foo(string $x = "")` may become non-nullable `string $x`; callers passing `$request->getVar()`, `$_GET[...]` or has_one accessors then throw `must be of type string, null given` — only at runtime on specific request paths. After the Rector run, grep for `getVar(` results flowing into typed methods; fix with `?? ""` at the caller or `?string $x = null` in the signature.
- **PHP set rewrites `switch` → `match`** — semantics differ (strict comparison, no fall-through); review those diffs.
- **`DataList::sort()` rejects raw SQL** (already since SS5): `->sort("RAND()")` throws — use `->orderBy("RAND()")`.
- **ideannotator legacy docblocks**: old `@@property \Foo dataRecord` / `@mixin \Foo dataRecord` lines from `silverleague/ideannotator` throw `InvalidArgumentException: The tag "@@property ..." does not seem to be wellformed` on `sake db:build`. Strip them (sed on the affected controllers) or regenerate annotations.
- **`public/_resources` gitignore**: SS5+/SS6 expose path is `_resources` (underscore); older projects only ignore `/public/resources`. Add both; if files were committed: `git rm -r --cached public/_resources`.
- **Abandoned binaries**: `h4cc/wkhtmltopdf-amd64` and similar legacy wrappers have no SS6-era support — switch to alternatives (`dompdf/dompdf`, `chrome-php/chrome`) or find a maintained fork.
- **Elemental** 4/5 → 6: `ElementalArea` API is largely compatible, but check element template paths and `getCMSFields` customisations in the CMS.

## After the hop

`vendor/bin/sake db:build --flush`, fix errors, grep for old FQCNs in non-PHP files, rewrite tasks, run tests, click-test CMS (login, page edit, asset upload, forms).
