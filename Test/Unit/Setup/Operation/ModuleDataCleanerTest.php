<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Test\Unit\Setup\Operation;

use Magento\Framework\App\ResourceConnection;
use Magetu\ModuleDataCleanUp\Setup\Operation\ModuleDataCleaner;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test for ModuleDataCleaner.
 *
 * @see ModuleDataCleaner
 */
class ModuleDataCleanerTest extends TestCase
{
    /**
     * @var AttributeCollectionFactory|MockObject
     */
    private AttributeCollectionFactory|MockObject $attributeCollectionFactoryMock;

    /**
     * @var AdapterInterface|MockObject
     */
    private AdapterInterface|MockObject $connectionMock;

    /**
     * @var ModuleDataCleaner
     */
    private ModuleDataCleaner $moduleDataCleaner;

    protected function setUp(): void
    {
        $resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $this->attributeCollectionFactoryMock = $this->createMock(AttributeCollectionFactory::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);

        // Configure the mock to return the mock connection when getConnection() is called.
        $resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);

        // Mock getTableName to return specific table names based on input.
        $resourceConnectionMock->method('getTableName')
            ->willReturnCallback(function (string $tableName) {
                $tables = [
                    'eav_attribute' => 'eav_attribute',
                    'patch_list' => 'patch_list',
                    'core_config_data' => 'core_config_data',
                    'setup_module' => 'setup_module'
                ];
                return $tables[$tableName] ?? $tableName;
            });

        // Initialize the ModuleDataCleaner with the mock setup and attribute collection factory.
        $this->moduleDataCleaner = new ModuleDataCleaner(
            $resourceConnectionMock,
            $this->attributeCollectionFactoryMock
        );
    }

    public function testRemoveAttributesWithMatchingAttributes(): void
    {
        $attributeCollectionMock = $this->createMock(AttributeCollection::class);
        // Mock the collection methods to support method chaining and return specific attribute mocks.
        $attributeCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $attributeCollectionMock->method('getItems')->willReturn([
            $this->createAttributeMock(1),
            $this->createAttributeMock(2)
        ]);

        // Configure the collection factory to return the mocked collection.
        $this->attributeCollectionFactoryMock
            ->method('create')
            ->willReturn($attributeCollectionMock);

        // Expect the delete method to be called with correct parameters to remove attributes.
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('eav_attribute'),
                $this->equalTo(['attribute_id IN (?)' => [1, 2]])
            );

        // Call the method under test.
        $this->moduleDataCleaner->removeAttributes(['attribute1', 'attribute2']);
    }

    public function testRemoveAttributeWithNoMatchingAttributes(): void
    {
        $attributeCollectionMock = $this->createMock(AttributeCollection::class);
        // Mock the collection methods to support method chaining and return an empty result.
        $attributeCollectionMock->method('addFieldToFilter')->willReturnSelf();
        $attributeCollectionMock->method('getItems')->willReturn([]);

        // Configure the collection factory to return the mocked collection.
        $this->attributeCollectionFactoryMock
            ->method('create')
            ->willReturn($attributeCollectionMock);

        // Expect the delete method to never be called.
        $this->connectionMock->expects($this->never())->method('delete');

        // Call the method under test.
        $this->moduleDataCleaner->removeAttributes(['non_existing_attribute']);
    }

    public function testRemovePatches(): void
    {
        // Patch records are matched by the module namespace, with backslashes escaped for LIKE.
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('patch_list'),
                $this->equalTo(['patch_name LIKE ?' => 'ShipperHQ\\\\Shipper\\\\%'])
            );

        // Call the method under test.
        $this->moduleDataCleaner->removePatches('ShipperHQ_Shipper');
    }

    public function testRemovePatchesWithEmptyModuleNameDoesNothing(): void
    {
        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removePatches('');
    }

    public function testRemoveConfigs(): void
    {
        // Track calls to the delete method to verify the parameters.
        $calls = [];

        // Define the expected SQL query patterns for deletion.
        $this->connectionMock->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($table, $where) use (&$calls) {
                $calls[] = [$table, $where];
            });

        // Trailing slash => section/group prefix match; no trailing slash => exact config path.
        $this->moduleDataCleaner->removeConfigs(['section1/group1/', 'section2/group2/field']);

        // Define the expected SQL query patterns for configuration deletion.
        $expectedCalls = [
            ['core_config_data', ['path LIKE ?' => 'section1/group1/%']],
            ['core_config_data', ['path = ?' => 'section2/group2/field']]
        ];

        // Assert that the delete method was called with the expected parameters.
        $this->assertEquals($expectedCalls, $calls);
    }

    public function testRemoveCarriersByModelDropsEachMatchedCarrierGroup(): void
    {
        // Two carrier groups point at the removed model class.
        $selectMock = $this->createMock(\Magento\Framework\DB\Select::class);
        $selectMock->method('from')->willReturnSelf();
        $selectMock->method('where')->willReturnSelf();
        $this->connectionMock->method('select')->willReturn($selectMock);
        $this->connectionMock->method('fetchCol')->willReturn([
            'carriers/shqshipper1/model',
            'carriers/shqfedexfreight3/model',
        ]);

        $calls = [];
        $this->connectionMock->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($table, $where) use (&$calls) {
                $calls[] = [$table, $where];
            });

        $this->moduleDataCleaner->removeCarriersByModel(['ShipperHQ\\Shipper\\Model\\Carrier\\Shipper']);

        // Each matched carrier's whole config group is removed by path prefix.
        $expectedCalls = [
            ['core_config_data', ['path LIKE ?' => 'carriers/shqshipper1/%']],
            ['core_config_data', ['path LIKE ?' => 'carriers/shqfedexfreight3/%']],
        ];
        $this->assertEquals($expectedCalls, $calls);
    }

    public function testRemoveCarriersByModelWithEmptyListDoesNothing(): void
    {
        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removeCarriersByModel([]);
    }

    public function testRemoveAclRules(): void
    {
        // The module's ACL resources are matched as resource_id LIKE 'Vendor_Module::%'.
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('authorization_rule'),
                $this->equalTo(['resource_id LIKE ?' => 'Vendor_Module::%'])
            );

        // Call the method under test.
        $this->moduleDataCleaner->removeAclRules('Vendor_Module');
    }

    public function testRemoveAclRulesWithEmptyModuleNameDoesNothing(): void
    {
        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removeAclRules('');
    }

    public function testRemoveUiBookmarks(): void
    {
        $namespaces = ['vendor_module_logs_listing', 'vendor_module_items_listing'];

        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('ui_bookmark'),
                $this->equalTo(['namespace IN (?)' => $namespaces])
            );

        $this->moduleDataCleaner->removeUiBookmarks($namespaces);
    }

    public function testRemoveUiBookmarksWithEmptyListDoesNothing(): void
    {
        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removeUiBookmarks([]);
    }

    public function testRemoveOrderStatusesRemovesUnusedStatuses(): void
    {
        $statuses = ['review_kount', 'decline_kount'];
        // No order references either status -> both are removable.
        $this->stubOrderStatusUsage([]);

        $calls = [];
        $this->connectionMock->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($table, $where) use (&$calls) {
                $calls[] = [$table, $where];
            });

        $this->moduleDataCleaner->removeOrderStatuses($statuses);

        // State mappings are deleted first, then the statuses themselves.
        $expectedCalls = [
            ['sales_order_status_state', ['status IN (?)' => $statuses]],
            ['sales_order_status', ['status IN (?)' => $statuses]]
        ];
        $this->assertEquals($expectedCalls, $calls);
    }

    public function testRemoveOrderStatusesSkipsStatusesStillUsedByOrders(): void
    {
        // Orders still use 'decline_kount' -> it is kept; only the unused 'review_kount' is removed.
        $this->stubOrderStatusUsage(['decline_kount']);

        $calls = [];
        $this->connectionMock->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($table, $where) use (&$calls) {
                $calls[] = [$table, $where];
            });

        $this->moduleDataCleaner->removeOrderStatuses(['review_kount', 'decline_kount']);

        $expectedCalls = [
            ['sales_order_status_state', ['status IN (?)' => ['review_kount']]],
            ['sales_order_status', ['status IN (?)' => ['review_kount']]]
        ];
        $this->assertEquals($expectedCalls, $calls);
    }

    public function testRemoveOrderStatusesKeepsAllWhenEveryCodeIsStillUsed(): void
    {
        $this->stubOrderStatusUsage(['review_kount', 'decline_kount']);

        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removeOrderStatuses(['review_kount', 'decline_kount']);
    }

    public function testRemoveOrderStatusesWithEmptyListDoesNothing(): void
    {
        $this->connectionMock->expects($this->never())->method('delete');

        $this->moduleDataCleaner->removeOrderStatuses([]);
    }

    public function testRemoveRecordInSetupModuleTable(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('delete')
            ->with(
                $this->equalTo('setup_module'),
                ['module = ?' => 'Test_Module']
            );

        // Call the method under test.
        $this->moduleDataCleaner->removeRecordInSetupModuleTable('Test_Module');
    }

    /**
     * Stub the "which of these statuses are still used by orders" lookup.
     *
     * @param string[] $inUse
     * @return void
     */
    private function stubOrderStatusUsage(array $inUse): void
    {
        $selectMock = $this->createMock(\Magento\Framework\DB\Select::class);
        $selectMock->method('from')->willReturnSelf();
        $selectMock->method('where')->willReturnSelf();
        $selectMock->method('distinct')->willReturnSelf();
        $this->connectionMock->method('select')->willReturn($selectMock);
        $this->connectionMock->method('fetchCol')->willReturn($inUse);
    }

    private function createAttributeMock(int $attributeId): MockObject
    {
        $attributeMock = $this->createMock(Attribute::class);
        $attributeMock->method('getData')
            ->willReturnCallback(static function (string $key = '') use ($attributeId) {
                return $key === 'attribute_id' ? $attributeId : null;
            });

        return $attributeMock;
    }
}
