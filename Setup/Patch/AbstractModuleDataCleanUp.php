<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Setup\Patch;

use Magetu\ModuleDataCleanUp\Setup\Operation\ModuleDataCleaner;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchInterface;

/**
 * Abstract class for data cleanup.
 *
 * This abstract class provides a foundation for creating data patches that clean up
 * or remove database data related to the module.
 *
 * To utilize this class, extend it to create a custom data patch
 * and declare the necessary properties in the \Magetu\ModuleDataCleanUp\Setup\Patch\Data namespace.
 */
abstract class AbstractModuleDataCleanUp implements DataPatchInterface
{
    /**
     * Module name in <VendorName>_<ModuleName> format.
     *
     * @var string
     */
    protected string $moduleName = '';

    /**
     * Module attribute codes associated with the module.
     *
     * @var string[]
     */
    protected array $attributeCodes = [];

    /**
     * Admin UI component listing namespaces whose saved views (ui_bookmark) should be removed.
     *
     * Unlike config/ACL/patches, grid namespaces are arbitrary and cannot be derived from the module
     * name, so declare them explicitly (e.g. 'vendor_module_entity_listing').
     *
     * @var string[]
     */
    protected array $uiBookmarkNamespaces = [];

    /**
     * List of `core_config_data` paths used by the module.
     *
     * Entries ending in `/` (e.g. 'section/group/') are removed as a prefix — everything beneath them.
     * Entries without a trailing slash (e.g. 'section/group/field') are removed as an exact path.
     *
     * @var string[]
     */
    protected array $configSectionGroups = [];

    /**
     * Carrier model classes (FQCN strings) whose `carriers/<code>/` config groups must be removed.
     *
     * For removed shipping extensions that registered carriers under generated codes which a fixed
     * config path can't match; every carrier whose model equals one of these is dropped whole.
     *
     * @var string[]
     */
    protected array $carrierModelClasses = [];

    /**
     * Custom order status codes the module registered in sales_order_status.
     *
     * @var string[]
     */
    protected array $orderStatuses = [];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ModuleDataCleaner $moduleDataCleaner
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ModuleDataCleaner $moduleDataCleaner
    ) {
    }

    /**
     * Applies the data changes.
     *
     * @return PatchInterface
     */
    public function apply(): PatchInterface
    {
        // Caution: Do not perform attribute removal operations between startSetup() and endSetup().
        // The startSetup() method temporarily disables the foreign key checks,
        // preventing the automatic deletion of values referencing the attributes being removed.
        $this->moduleDataCleaner->removeAttributes($this->attributeCodes);

        $this->moduleDataSetup->startSetup();

        $this->moduleDataCleaner->removePatches($this->moduleName);
        $this->moduleDataCleaner->removeConfigs($this->configSectionGroups);
        $this->moduleDataCleaner->removeCarriersByModel($this->carrierModelClasses);
        $this->moduleDataCleaner->removeAclRules($this->moduleName);
        $this->moduleDataCleaner->removeUiBookmarks($this->uiBookmarkNamespaces);
        $this->moduleDataCleaner->removeOrderStatuses($this->orderStatuses);
        $this->moduleDataCleaner->removeRecordInSetupModuleTable($this->moduleName);

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
