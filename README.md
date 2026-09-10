# Magetu_ModuleDataCleanUp

A small framework for **cleaning up the database footprint a Magento 2 module leaves behind after it is
uninstalled**. Disabling or deleting a module's code does not remove what it created — tables, columns,
indexes, EAV attributes, configuration, admin ACL rules, patch history, saved grid views, and its
`setup_module` record all remain. This module provides two abstract setup-patch base classes that a
consuming module extends to declare *what* to remove; the removal logic lives here and is shared.

## How it works

- **`Setup\Patch\AbstractModuleDataCleanUp`** (a `DataPatchInterface`) — removes *data* rows, delegating
  to `Setup\Operation\ModuleDataCleaner`.
- **`Setup\Patch\AbstractModuleSchemaCleanUp`** (a `SchemaPatchInterface`) — drops *schema* objects,
  delegating to `Setup\Operation\ModuleSchemaCleaner`.

A consuming module creates one patch per removed module, extends the relevant base class, and sets a few
protected properties. The patches run once during `bin/magento setup:upgrade` and are then recorded in
`patch_list` like any other patch. Every operation is **guarded by existence checks**, so the patches are
safe to run even after the target module's code is already gone.

## Usage

1. Add a `<sequence>` dependency on this module so the base classes load first:

   ```xml
   <!-- Acme/Cleanup/etc/module.xml -->
   <module name="Acme_Cleanup">
       <sequence>
           <module name="Magetu_ModuleDataCleanUp"/>
       </sequence>
   </module>
   ```

2. Create a **data patch** per removed module (no constructor needed — it is inherited):

   ```php
   namespace Acme\Cleanup\Setup\Patch\Data;

   use Magetu\ModuleDataCleanUp\Setup\Patch\AbstractModuleDataCleanUp;

   class RemoveFooBar extends AbstractModuleDataCleanUp
   {
       protected string $moduleName = 'Foo_Bar';

       /** @var string[] */
       protected array $attributeCodes = ['foo_bar_flag'];

       /** @var string[] */
       protected array $configSectionGroups = ['foobar/', 'payment/foo_bar/active'];
   }
   ```

3. Add a **schema patch** only when the module owns tables/views or modified core tables:

   ```php
   namespace Acme\Cleanup\Setup\Patch\Schema;

   use Magetu\ModuleDataCleanUp\Setup\Patch\AbstractModuleSchemaCleanUp;

   class RemoveFooBar extends AbstractModuleSchemaCleanUp
   {
       /** @var string[] */
       protected array $tableNames = ['foo_bar_item', 'foo_bar'];

       /** @var array<string, string[]> */
       protected array $coreTableColumnMappings = ['sales_order' => ['foo_bar_token']];
   }
   ```

## What the data patch removes

**Derived automatically from `$moduleName`** — no extra properties:

| Target | Rule |
|---|---|
| `setup_module` | the module's row |
| `patch_list` | every patch under the `Vendor\Module\…` namespace |
| `authorization_rule` | every admin ACL resource namespaced `Vendor_Module::…` |

**Declared via properties:**

| Property | Removes from | Notes |
|---|---|---|
| `$attributeCodes` `string[]` | `eav_attribute` | FK-cascades to `catalog/customer_eav_attribute`, `eav_entity_attribute`, `eav_attribute_option(_value)`, `eav_attribute_label`, and all `*_entity_{varchar,int,…}` value rows. Runs **before** `startSetup()` so the cascade is not suppressed by disabled foreign-key checks. |
| `$configSectionGroups` `string[]` | `core_config_data` | A trailing `/` is a prefix match (`LIKE 'foobar/%'`); an entry without one is an exact path (`path = 'payment/foo_bar/active'`). |
| `$uiBookmarkNamespaces` `string[]` | `ui_bookmark` | Saved admin grid views. Grid namespaces are arbitrary and cannot be derived, so declare them (e.g. `'foo_bar_listing'`). |
| `$orderStatuses` `string[]` | `sales_order_status` / `sales_order_status_state` | Custom order statuses the module registered. A code still referenced by any order is **kept** (so those orders don't lose their label); only unused codes are removed. State mappings are removed before the statuses. |

`$attributeCodes` removes EAV attributes by **hard delete** (cascades to all product/customer value rows — irreversible). Only list attributes whose data is genuinely dead; leave anything a surviving feature might still read.

## What the schema patch removes

| Property | Action | Notes |
|---|---|---|
| `$tableNames` `string[]` | `DROP TABLE` (+ the table's triggers) | List child/referencing tables before the tables they reference. Order is forgiving — tables are dropped within `startSetup()`/`endSetup()`, where foreign-key checks are disabled. |
| `$viewNames` `string[]` | `DROP VIEW` | |
| `$coreTableColumnMappings` `array<string,string[]>` | `DROP COLUMN` | `['core_table' => ['col1','col2']]` — only columns the module added to **kept** (core) tables. Columns on dropped tables go with the table. |
| `$coreTableColumnMappingsIfUnused` `array<string,string[]>` | `DROP COLUMN` **only when empty** | Same shape, but each column is checked at apply time and **kept if any row still holds a value** — so transaction data a surviving feature may need is not destroyed. Use for columns carrying data (e.g. payment/refund references); columns with no data are dropped. |
| `$coreTableIndexMappings` `array<string,string[]>` | `DROP INDEX` | `['core_table' => ['INDEX_NAME']]` — indexes added to kept tables. |

## Operation classes (low-level primitives)

- `Setup\Operation\ModuleDataCleaner` — `removeAttributes()`, `removePatches()`, `removeConfigs()`,
  `removeAclRules()`, `removeUiBookmarks()`, `removeRecordInSetupModuleTable()`.
- `Setup\Operation\ModuleSchemaCleaner` — `removeTables()`, `removeViews()`, `removeCoreTableColumns()`,
  `removeUnusedCoreTableColumns()` (drops a column only when it holds no data),
  `removeCoreTableIndexes()`, `dropTriggersForTables()`.

## Not handled (decide per removal)

Intentionally out of scope — these are user data, transient, or not derivable from a module name:

- **`email_template`** rows (admin-customised templates) — user data; never auto-deleted.
- **`cron_schedule`** rows — transient; Magento prunes them.
- **`url_rewrite`**, **CMS blocks/pages**, **widget instances**, custom **EAV entity types / attribute
  sets** — add a dedicated patch only if the removed module actually created them.
- **Front-end markup** in themes/templates that referenced the module by brand or feature token (CSS
  classes, Knockout bindings, layout block names, template files) — these live in no DB table; remove
  them from the theme/templates directly.
- **Files** under `pub/media`, `pub`, or `lib` that the extension extracted — delete separately.
- **Products of a removed custom product type** — the type falls back to *simple* at runtime; reassign
  or delete those rows as a business decision.
- A **reindex** may be required afterwards if the cleanup removed EAV attributes, a product type, or
  other catalog/indexed data (config-only removals do not need one).

## Requirements

Built for Adobe Commerce / Magento Open Source **2.4.x** and **Mage-OS**, **PHP 8.1 – 8.5**.

The unit suite (`Test/Unit/`) runs on **PHPUnit 9.6.33+, 10.5.62+, or 12.5.8+**. It uses no APIs
removed in PHPUnit 10/12. Note PHPUnit 12 itself requires PHP 8.3+.
