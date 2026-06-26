<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Setup\Patch;

use Magetu\ModuleDataCleanUp\Setup\Operation\ModuleSchemaCleaner;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;
use Zend_Db_Statement_Exception;

/**
 * Abstract class for schema cleanup.
 *
 * This abstract class provides a foundation for creating schema patches that clean up
 * or remove database schema elements related to the module.
 *
 * To utilize this class, extend it to create a custom schema patch
 * and declare the necessary properties in the \Magetu\ModuleDataCleanUp\Setup\Patch\Schema namespace.
 */
abstract class AbstractModuleSchemaCleanUp implements SchemaPatchInterface
{
    /**
     * List of table names associated with the module.
     *
     * Ensure to declare tables that reference other tables before declaring the tables they are referencing.
     * This order helps prevent the "a foreign key constraint fails" error.
     *
     * @var string[]
     */
    protected array $tableNames = [];

    /**
     * List of view name associated with the module.
     *
     * @var string[]
     */
    protected array $viewNames = [];

    /**
     * Maps columns from Magento core tables to those created by the module.
     *
     * @var array<string,string[]>
     */
    protected array $coreTableColumnMappings = [];

    /**
     * Maps kept (core) tables to module-created columns that are dropped ONLY when empty.
     *
     * Unlike $coreTableColumnMappings (unconditional drop), each column listed here is kept if
     * any row still holds a value, so historical data a surviving feature may need is never
     * destroyed. Use for columns carrying transaction data (e.g. payment/refund references).
     *
     * @var array<string,string[]>
     */
    protected array $coreTableColumnMappingsIfUnused = [];

    /**
     * Maps Magento core tables to the index names created by the module.
     *
     * Each entry is in the format 'table_name' => ['index_name_1', 'index_name_2'].
     * List indexes added to tables that are kept; indexes on the module's own tables are
     * removed automatically when those tables are dropped.
     *
     * @var array<string,string[]>
     */
    protected array $coreTableIndexMappings = [];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ModuleSchemaCleaner $moduleSchemaCleaner
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ModuleSchemaCleaner $moduleSchemaCleaner
    ) {
    }

    /**
     * Applies the schema changes.
     *
     * @return PatchInterface
     * @throws Zend_Db_Statement_Exception
     */
    public function apply(): PatchInterface
    {
        $this->moduleDataSetup->startSetup();

        $this->moduleSchemaCleaner->removeTables($this->tableNames);
        $this->moduleSchemaCleaner->removeViews($this->viewNames);
        $this->moduleSchemaCleaner->removeCoreTableColumns($this->coreTableColumnMappings);
        $this->moduleSchemaCleaner->removeUnusedCoreTableColumns($this->coreTableColumnMappingsIfUnused);
        $this->moduleSchemaCleaner->removeCoreTableIndexes($this->coreTableIndexMappings);
        $this->moduleSchemaCleaner->dropTriggersForTables($this->tableNames);

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * Example of implementation:
     *
     * [
     *      \Vendor_Name\Module_Name\Setup\Patch\Patch1::class,
     *      \Vendor_Name\Module_Name\Setup\Patch\Patch2::class
     * ]
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Get aliases (previous names) for the patch.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
