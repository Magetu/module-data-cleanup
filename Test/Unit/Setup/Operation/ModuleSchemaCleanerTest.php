<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Test\Unit\Setup\Operation;

use Magento\Framework\App\ResourceConnection;
use Magetu\ModuleDataCleanUp\Setup\Operation\ModuleSchemaCleaner;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Zend_Db_Statement_Exception;
use Zend_Db_Statement_Interface;

/**
 * Test for ModuleSchemaCleaner.
 *
 * @see ModuleSchemaCleaner
 */
class ModuleSchemaCleanerTest extends TestCase
{
    /**
     * @var AdapterInterface|MockObject
     */
    private AdapterInterface|MockObject $connectionMock;

    /**
     * @var ModuleSchemaCleaner
     */
    private ModuleSchemaCleaner $moduleSchemaCleaner;

    protected function setUp(): void
    {
        $resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);

        // Configure the mock to return the mock connection when getConnection() is called.
        $resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);

        // Mock getTable to return specific table names.
        $resourceConnectionMock->method('getTableName')
            ->willReturnCallback(function (string $tableName) {
                return $tableName;
            });

        // Mock quoteIdentifier to return the table names wrapped in backticks.
        $this->connectionMock->method('quoteIdentifier')
            ->willReturnCallback(function (string $viewName) {
                return "`$viewName`";
            });

        // Initialize the ModuleSchemaCleaner with the mock setup.
        $this->moduleSchemaCleaner = new ModuleSchemaCleaner($resourceConnectionMock);
    }

    public function testRemoveTablesWithExistingTables(): void
    {
        // Mock table existence check.
        $this->connectionMock->method('isTableExists')
            ->willReturn(true);

        // Expect dropTable to be called twice with the correct table names.
        $this->connectionMock->expects($this->exactly(2))
            ->method('dropTable')
            ->willReturnCallback(function (string $tableName) {
                // Verify that the correct table names are being used.
                $expectedTables = ['table1', 'table2'];
                $this->assertContains($tableName, $expectedTables);
            });

        // Call the method under test.
        $this->moduleSchemaCleaner->removeTables(['table1', 'table2']);
    }

    public function testRemoveTablesWithNonExistentTables(): void
    {
        // Mock table existence check.
        $this->connectionMock->method('isTableExists')
            ->willReturn(false);

        // Expect dropTable to never be called.
        $this->connectionMock->expects($this->never())->method('dropTable');

        // Call the method under test.
        $this->moduleSchemaCleaner->removeTables(['non_existent_table']);
    }

    public function testRemoveViews(): void
    {
        // Define view names and their corresponding DROP VIEW queries.
        $viewNames = ['view1', 'view2'];
        $argsSequence = array_map(function ($viewName) {
            return "DROP VIEW IF EXISTS `$viewName`;";
        }, $viewNames);

        // Expect query to be called for each DROP VIEW statement.
        $this->connectionMock->expects($this->exactly(count($viewNames)))
            ->method('query')
            ->willReturnCallback(function (string $query) use (&$argsSequence) {
                $expectedQuery = array_shift($argsSequence);
                $this->assertEquals($expectedQuery, $query);
            });

        // Call the method under test.
        $this->moduleSchemaCleaner->removeViews($viewNames);
    }

    public function testRemoveCoreTableColumns(): void
    {
        // Define expected calls for isTableExists and tableColumnExists.
        $expectedTableChecks = [
            'core_table1' => true, // This table exists
            'core_table2' => true, // This table exists
        ];

        // Define columns existence checks
        $expectedColumnChecks = [
            'core_table1' => ['column1' => true], // Column exists
            'core_table2' => ['column2' => true], // Column exists
        ];

        // Mock table existence checks
        $this->connectionMock->expects($this->exactly(count($expectedTableChecks)))
            ->method('isTableExists')
            ->willReturnCallback(function ($tableName) use ($expectedTableChecks) {
                $this->assertArrayHasKey($tableName, $expectedTableChecks);
                return $expectedTableChecks[$tableName];
            });

        // Mock column existence checks
        $this->connectionMock->expects($this->exactly(count(array_merge(...array_values($expectedColumnChecks)))))
            ->method('tableColumnExists')
            ->willReturnCallback(function ($tableName, $columnName) use ($expectedColumnChecks) {
                $this->assertArrayHasKey($tableName, $expectedColumnChecks);
                return $expectedColumnChecks[$tableName][$columnName] ?? false;
            });

        // Define expected calls for dropColumn.
        $expectedDropCalls = [
            ['core_table1', 'column1'],
            ['core_table2', 'column2']
        ];

        // Expect dropColumn to be called with the correct table and column names.
        $this->connectionMock->expects($this->exactly(count($expectedDropCalls)))
            ->method('dropColumn')
            ->willReturnCallback(function (string $tableName, string $columnName) use ($expectedDropCalls) {
                $this->assertContains([$tableName, $columnName], $expectedDropCalls);
            });

        // Call the method under test.
        $this->moduleSchemaCleaner->removeCoreTableColumns([
            'core_table1' => ['column1'],
            'core_table2' => ['column2']
        ]);
    }

    public function testRemoveUnusedCoreTableColumnsDropsEmptyColumn(): void
    {
        $this->connectionMock->method('isTableExists')->willReturn(true);
        $this->connectionMock->method('tableColumnExists')->willReturn(true);
        $this->connectionMock->method('select')->willReturn($this->dataSelectMock());

        // No row holds a value -> column is unused -> dropped.
        $this->connectionMock->method('fetchOne')->willReturn(false);

        $this->connectionMock->expects($this->once())
            ->method('dropColumn')
            ->with('sales_creditmemo', 'splitit_refund_id');

        $this->moduleSchemaCleaner->removeUnusedCoreTableColumns([
            'sales_creditmemo' => ['splitit_refund_id'],
        ]);
    }

    public function testRemoveUnusedCoreTableColumnsKeepsColumnWithData(): void
    {
        $this->connectionMock->method('isTableExists')->willReturn(true);
        $this->connectionMock->method('tableColumnExists')->willReturn(true);
        $this->connectionMock->method('select')->willReturn($this->dataSelectMock());

        // A row still holds a value -> column is in use -> preserved.
        $this->connectionMock->method('fetchOne')->willReturn('1');

        $this->connectionMock->expects($this->never())->method('dropColumn');

        $this->moduleSchemaCleaner->removeUnusedCoreTableColumns([
            'sales_creditmemo' => ['splitit_refund_id'],
        ]);
    }

    /**
     * A Select mock whose fluent builders return itself, for the columnHasData() probe.
     *
     * @return Select|MockObject
     */
    private function dataSelectMock(): Select|MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    public function testRemoveCoreTableIndexes(): void
    {
        // Both mapped tables exist.
        $this->connectionMock->method('isTableExists')->willReturn(true);

        // Existing indexes per table, keyed UPPERCASE as getIndexList() returns them.
        $indexListPerTable = [
            'core_table1' => [
                'MODULE_INDEX1' => ['KEY_NAME' => 'MODULE_INDEX1'],
            ],
            'core_table2' => [
                'MODULE_INDEX2' => ['KEY_NAME' => 'MODULE_INDEX2'],
            ],
        ];

        $this->connectionMock->expects($this->exactly(count($indexListPerTable)))
            ->method('getIndexList')
            ->willReturnCallback(function (string $tableName) use ($indexListPerTable) {
                $this->assertArrayHasKey($tableName, $indexListPerTable);
                return $indexListPerTable[$tableName];
            });

        // Expect dropIndex for the existing indexes only; the missing one is skipped.
        // The original-case index name is passed through (existence is matched case-insensitively).
        $expectedDropCalls = [
            ['core_table1', 'module_index1'],
            ['core_table2', 'MODULE_INDEX2'],
        ];

        $this->connectionMock->expects($this->exactly(count($expectedDropCalls)))
            ->method('dropIndex')
            ->willReturnCallback(function (string $tableName, string $indexName) use ($expectedDropCalls) {
                $this->assertContains([$tableName, $indexName], $expectedDropCalls);
                return true;
            });

        // 'MISSING_INDEX' is not in the index list and must be ignored (case-insensitive match).
        $this->moduleSchemaCleaner->removeCoreTableIndexes([
            'core_table1' => ['module_index1', 'MISSING_INDEX'],
            'core_table2' => ['MODULE_INDEX2'],
        ]);
    }

    public function testRemoveCoreTableIndexesSkipsNonExistentTable(): void
    {
        // The mapped table does not exist.
        $this->connectionMock->method('isTableExists')->willReturn(false);

        // Neither index introspection nor drop should run.
        $this->connectionMock->expects($this->never())->method('getIndexList');
        $this->connectionMock->expects($this->never())->method('dropIndex');

        $this->moduleSchemaCleaner->removeCoreTableIndexes([
            'non_existent_table' => ['some_index'],
        ]);
    }

    /**
     * @throws Zend_Db_Statement_Exception
     */
    public function testDropTriggersForTables(): void
    {
        // Mock the result of the SHOW TRIGGERS LIKE query.
        $triggersResult = [
            ['Trigger' => 'trigger1'],
            ['Trigger' => 'trigger2']
        ];

        // Mock the query method to return specific results for each call.
        $this->connectionMock->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->createMockStatement($triggersResult), // Result for SHOW TRIGGERS LIKE
                $this->createMockStatement([]) // Empty result for DROP TRIGGER queries
            );

        // Track the queries executed.
        $executedQueries = [];

        $this->connectionMock->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $query) use (&$executedQueries) {
                $executedQueries[] = $query;
                return $this->createMockStatement($query);
            });

        // Call the method under test.
        $this->moduleSchemaCleaner->dropTriggersForTables(['table1']);

        // Define the expected queries.
        $expectedQueries = [
            'SHOW TRIGGERS LIKE \'table1\'',
            'DROP TRIGGER IF EXISTS trigger1',
            'DROP TRIGGER IF EXISTS trigger2'
        ];

        // Verify that the queries were executed in the correct order.
        $this->assertEquals($expectedQueries, $executedQueries);
    }

    /**
     * Create a mock statement object.
     *
     * @param mixed $result
     * @return Zend_Db_Statement_Interface
     */
    private function createMockStatement(mixed $result): Zend_Db_Statement_Interface
    {
        $statementMock = $this->createMock(Zend_Db_Statement_Interface::class);

        if (is_array($result)) {
            // Return the result array for fetchAll if the result is an array.
            $statementMock->method('fetchAll')->willReturn($result);
        } else {
            // Return an empty array for fetchAll if the result is a query string.
            $statementMock->method('fetchAll')->willReturn([]);
        }

        return $statementMock;
    }
}
