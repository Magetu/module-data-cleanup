<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\ModuleDataCleanUp\Setup\Operation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr;
use Zend_Db_Statement_Exception;

class ModuleSchemaCleaner
{
    /**
     * @var AdapterInterface
     */
    private AdapterInterface $connection;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
        $this->connection = $resource->getConnection();
    }

    /**
     * Removes tables associated with the module.
     *
     * @param string[] $tableNames
     * @return void
     */
    public function removeTables(array $tableNames): void
    {
        foreach ($tableNames as $tableName) {
            // Get the fully qualified table name with prefix and suffix
            $tableName = $this->resource->getTableName($tableName);
            if ($this->connection->isTableExists($tableName)) {
                $this->connection->dropTable($tableName);
            }
        }
    }

    /**
     * Removes views associated with the module.
     *
     * @param string[] $viewNames
     * @return void
     */
    public function removeViews(array $viewNames): void
    {
        foreach ($viewNames as $viewName) {
            // Quote and escape the trigger name to avoid SQL injection and handle special characters
            $quotedViewName = $this->connection->quoteIdentifier($viewName);
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $this->connection->query("DROP VIEW IF EXISTS $quotedViewName;");
        }
    }

    /**
     * Remove columns from Magento core tables that were created by the module.
     *
     * @param array<string,string[]> $coreTableColumnMappings
     * @return void
     */
    public function removeCoreTableColumns(array $coreTableColumnMappings): void
    {
        foreach ($coreTableColumnMappings as $tableName => $columnNames) {
            // Get the fully qualified table name with prefix and suffix
            $tableName = $this->resource->getTableName($tableName);
            if (!$this->connection->isTableExists($tableName)) {
                continue;
            }
            foreach ($columnNames as $columnName) {
                if (!$this->connection->tableColumnExists($tableName, $columnName)) {
                    continue;
                }
                $this->connection->dropColumn($tableName, $columnName);
            }
        }
    }

    /**
     * Drop module-created columns from kept (core) tables, but only when they hold no data.
     *
     * The column's data is checked at apply time: if any row still has a non-null, non-empty
     * value the column is kept (so transaction history a surviving feature may need is not
     * destroyed) and the orphaned definition left harmless; otherwise it is dropped. Use this
     * in place of removeCoreTableColumns() for columns whose data must not be deleted blindly.
     *
     * @param array<string,string[]> $coreTableColumnMappings
     * @return void
     */
    public function removeUnusedCoreTableColumns(array $coreTableColumnMappings): void
    {
        foreach ($coreTableColumnMappings as $tableName => $columnNames) {
            // Get the fully qualified table name with prefix and suffix
            $tableName = $this->resource->getTableName($tableName);
            if (!$this->connection->isTableExists($tableName)) {
                continue;
            }
            foreach ($columnNames as $columnName) {
                if (!$this->connection->tableColumnExists($tableName, $columnName)) {
                    continue;
                }
                if ($this->columnHasData($tableName, $columnName)) {
                    continue;
                }
                $this->connection->dropColumn($tableName, $columnName);
            }
        }
    }

    /**
     * Whether any row holds a non-null, non-empty value in the given column.
     *
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    private function columnHasData(string $tableName, string $columnName): bool
    {
        $quotedColumn = $this->connection->quoteIdentifier($columnName);
        $select = $this->connection->select()
            ->from($tableName, new Zend_Db_Expr('1'))
            ->where($quotedColumn . ' IS NOT NULL')
            ->where($quotedColumn . " <> ''")
            ->limit(1);

        return (bool) $this->connection->fetchOne($select);
    }

    /**
     * Drop indexes from Magento core tables that were created by the module.
     *
     * Use this for indexes added to tables that are kept (e.g. core tables). Indexes on the
     * module's own tables do not need to be listed here since those tables are dropped entirely.
     *
     * @param array<string,string[]> $coreTableIndexMappings
     * @return void
     */
    public function removeCoreTableIndexes(array $coreTableIndexMappings): void
    {
        foreach ($coreTableIndexMappings as $tableName => $indexNames) {
            // Get the fully qualified table name with prefix and suffix
            $tableName = $this->resource->getTableName($tableName);
            if (!$this->connection->isTableExists($tableName)) {
                continue;
            }
            // getIndexList() keys are the UPPERCASE index names (PRIMARY stays as 'PRIMARY').
            $existingIndexes = $this->connection->getIndexList($tableName);
            foreach ($indexNames as $indexName) {
                if (!isset($existingIndexes[strtoupper($indexName)])) {
                    continue;
                }
                $this->connection->dropIndex($tableName, $indexName);
            }
        }
    }

    /**
     * Drops triggers associated with the module's tables.
     *
     * @param string[] $tableNames
     * @return void
     * @throws Zend_Db_Statement_Exception
     */
    public function dropTriggersForTables(array $tableNames): void
    {
        foreach ($tableNames as $tableName) {
            $this->dropTriggers($this->resource->getTableName($tableName));
        }
    }

    /**
     * Drops all triggers for a given table.
     *
     * @param string $tableName
     * @return void
     * @throws Zend_Db_Statement_Exception
     */
    public function dropTriggers(string $tableName): void
    {
        $triggers = $this->connection->query('SHOW TRIGGERS LIKE \'' . $tableName . '\'')->fetchAll();

        if (!$triggers) {
            return;
        }

        foreach ($triggers as $trigger) {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $this->connection->query('DROP TRIGGER IF EXISTS ' . $trigger['Trigger']);
        }
    }
}
