<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Setup\Operation;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ModuleDataCleaner
{
    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @param ResourceConnection $resource
     * @param AttributeCollectionFactory $attributeCollectionFactory
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
        $this->connection = $resource->getConnection();
    }

    /**
     * Remove attributes from the EAV attribute table.
     *
     * @param string[] $attributeCodes
     * @return void
     */
    public function removeAttributes(array $attributeCodes): void
    {
        if (empty($attributeCodes)) {
            return;
        }

        $items = $this->getAttributes($attributeCodes)->getItems();

        if (empty($items)) {
            return;
        }

        $attributeIds = array_map(
            static function ($eavAttribute) {
                return (int) $eavAttribute->getData('attribute_id');
            },
            $items
        );

        $this->connection->delete(
            $this->resource->getTableName('eav_attribute'),
            ['attribute_id IN (?)' => $attributeIds]
        );
    }

    /**
     * Remove the module's patch records from the patch_list table.
     *
     * Patches are stored as fully-qualified class names (e.g. Vendor\Module\Setup\Patch\Data\Xxx),
     * so every patch the module registered is matched by the namespace derived from its module name.
     * Backslashes are doubled because the LIKE operator treats `\` as its escape character.
     *
     * @param string $moduleName
     * @return void
     */
    public function removePatches(string $moduleName): void
    {
        if (!$moduleName) {
            return;
        }

        $namespacePrefix = str_replace('_', '\\', $moduleName) . '\\';
        $likePattern = str_replace('\\', '\\\\', $namespacePrefix) . '%';
        $this->connection->delete(
            $this->resource->getTableName('patch_list'),
            ['patch_name LIKE ?' => $likePattern]
        );
    }

    /**
     * Remove module config records from the core_config_data table.
     *
     * An entry ending in `/` is treated as a section/group prefix and everything beneath it is removed
     * (`LIKE 'prefix%'`); an entry without a trailing slash is removed as an exact config path, so it
     * cannot accidentally match sibling paths that share the same leading text.
     *
     * @param string[] $configSectionGroups
     * @return void
     */
    public function removeConfigs(array $configSectionGroups): void
    {
        $tableName = $this->resource->getTableName('core_config_data');

        foreach ($configSectionGroups as $configSectionGroup) {
            $condition = str_ends_with($configSectionGroup, '/')
                ? ['path LIKE ?' => $configSectionGroup . '%']
                : ['path = ?' => $configSectionGroup];
            $this->connection->delete($tableName, $condition);
        }
    }

    /**
     * Remove shipping carriers whose model class no longer exists.
     *
     * Some extensions register carriers under dynamically generated codes (e.g. ShipperHQ's per-method
     * virtual carriers shqshipper1, shqfedexfreight3, ...) that no fixed config path can match, yet all
     * share a `carriers/<code>/model` value. Magento instantiates every active carrier's model while
     * collecting shipping rates, so a row left pointing at a deleted class throws a ReflectionException
     * and breaks checkout. This finds such carriers by model value and removes each one's whole
     * `carriers/<code>/` config group.
     *
     * @param string[] $modelClasses Fully-qualified carrier model class names that have been removed.
     * @return void
     */
    public function removeCarriersByModel(array $modelClasses): void
    {
        if (empty($modelClasses)) {
            return;
        }

        $tableName = $this->resource->getTableName('core_config_data');

        $modelPaths = $this->connection->fetchCol(
            $this->connection->select()
                ->from($tableName, 'path')
                ->where('path LIKE ?', 'carriers/%/model')
                ->where('value IN (?)', $modelClasses)
        );

        foreach ($modelPaths as $modelPath) {
            // 'carriers/<code>/model' -> drop everything under 'carriers/<code>/'.
            $groupPrefix = substr($modelPath, 0, (int) strrpos($modelPath, '/') + 1);
            $this->connection->delete($tableName, ['path LIKE ?' => $groupPrefix . '%']);
        }
    }

    /**
     * Remove the module's admin ACL rules from the authorization_rule table.
     *
     * Admin ACL resources are namespaced as `<Vendor_Module>::<resource>`, so every rule the module
     * registered is matched by `resource_id LIKE '<Vendor_Module>::%'`.
     *
     * @param string $moduleName
     * @return void
     */
    public function removeAclRules(string $moduleName): void
    {
        if (!$moduleName) {
            return;
        }

        $this->connection->delete(
            $this->resource->getTableName('authorization_rule'),
            ['resource_id LIKE ?' => $moduleName . '::%']
        );
    }

    /**
     * Remove saved admin grid views (ui_bookmark) for the module's UI component listings.
     *
     * Grid namespaces are arbitrary and cannot be derived from the module name, so they are passed
     * in explicitly.
     *
     * @param string[] $uiComponentNamespaces
     * @return void
     */
    public function removeUiBookmarks(array $uiComponentNamespaces): void
    {
        if (empty($uiComponentNamespaces)) {
            return;
        }

        $this->connection->delete(
            $this->resource->getTableName('ui_bookmark'),
            ['namespace IN (?)' => $uiComponentNamespaces]
        );
    }

    /**
     * Remove custom order statuses the module registered.
     *
     * A status still referenced by an order is kept: `sales_order.status` is a plain varchar, so the
     * order would survive but lose its label (blank status in the admin grid/view, broken filters).
     * Only codes not used by any order are removed; the state mappings (sales_order_status_state) are
     * deleted before the statuses themselves (sales_order_status), as the former references the latter.
     *
     * @param string[] $statusCodes
     * @return void
     */
    public function removeOrderStatuses(array $statusCodes): void
    {
        if (empty($statusCodes)) {
            return;
        }

        $inUse = $this->connection->fetchCol(
            $this->connection->select()
                ->from($this->resource->getTableName('sales_order'), 'status')
                ->where('status IN (?)', $statusCodes)
                ->distinct()
        );
        $removable = array_values(array_diff($statusCodes, $inUse));
        if (empty($removable)) {
            return;
        }

        $this->connection->delete(
            $this->resource->getTableName('sales_order_status_state'),
            ['status IN (?)' => $removable]
        );
        $this->connection->delete(
            $this->resource->getTableName('sales_order_status'),
            ['status IN (?)' => $removable]
        );
    }

    /**
     * Remove module record from setup_module table.
     *
     * @param string $moduleName
     * @return void
     */
    public function removeRecordInSetupModuleTable(string $moduleName): void
    {
        if ($moduleName) {
            $this->connection->delete(
                $this->resource->getTableName('setup_module'),
                ['module = ?' => $moduleName]
            );
        }
    }

    /**
     * Retrieve a collection of attributes filtered by attribute codes.
     *
     * @param string[] $attributeCodes
     * @return AttributeCollection
     */
    private function getAttributes(array $attributeCodes): AttributeCollection
    {
        return $this->attributeCollectionFactory->create()
            ->addFieldToFilter('attribute_code', ['in' => $attributeCodes]);
    }
}
