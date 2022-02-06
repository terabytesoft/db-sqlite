<?php

declare(strict_types=1);

namespace Yiisoft\Db\Sqlite\PDO;

use Generator;
use JsonException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Constraint\Constraint;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Expression\ExpressionBuilder;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\Conditions\InCondition;
use Yiisoft\Db\Query\Conditions\LikeCondition;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Db\Schema\Schema;
use Yiisoft\Db\Sqlite\Condition\InConditionBuilder;
use Yiisoft\Db\Sqlite\Condition\LikeConditionBuilder;
use Yiisoft\Strings\NumericHelper;

use function array_filter;
use function array_merge;
use function implode;
use function is_float;
use function is_string;
use function ltrim;
use function reset;
use function strrpos;
use function trim;
use function version_compare;

final class QueryBuilderPDOSqlite extends QueryBuilder
{
    /**
     * @var array mapping from abstract column types (keys) to physical column types (values).
     *
     * @psalm-var array<string, string>
     */
    protected array $typeMap = [
        Schema::TYPE_PK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_UPK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_BIGPK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_UBIGPK => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
        Schema::TYPE_CHAR => 'char(1)',
        Schema::TYPE_STRING => 'varchar(255)',
        Schema::TYPE_TEXT => 'text',
        Schema::TYPE_TINYINT => 'tinyint',
        Schema::TYPE_SMALLINT => 'smallint',
        Schema::TYPE_INTEGER => 'integer',
        Schema::TYPE_BIGINT => 'bigint',
        Schema::TYPE_FLOAT => 'float',
        Schema::TYPE_DOUBLE => 'double',
        Schema::TYPE_DECIMAL => 'decimal(10,0)',
        Schema::TYPE_DATETIME => 'datetime',
        Schema::TYPE_TIMESTAMP => 'timestamp',
        Schema::TYPE_TIME => 'time',
        Schema::TYPE_DATE => 'date',
        Schema::TYPE_BINARY => 'blob',
        Schema::TYPE_BOOLEAN => 'boolean',
        Schema::TYPE_MONEY => 'decimal(19,4)',
    ];

    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct($db->getQuoter(), $db->getSchema());
    }

    /**
     * Generates a batch INSERT SQL statement.
     *
     * For example,
     *
     * ```php
     * $connection->createCommand()->batchInsert('user', ['name', 'age'], [
     *     ['Tom', 30],
     *     ['Jane', 20],
     *     ['Linda', 25],
     * ])->execute();
     * ```
     *
     * Note that the values in each row must match the corresponding column names.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array $columns the column names
     * @param iterable|Generator $rows the rows to be batched inserted into the table
     * @param array $params
     *
     * @return string the batch INSERT SQL statement.
     *@throws \Exception|InvalidArgumentException|InvalidConfigException|NotSupportedException
     *
     */
    public function batchInsert(string $table, array $columns, iterable|Generator $rows, array &$params = []): string
    {
        if (empty($rows)) {
            return '';
        }

        /**
         * SQLite supports batch insert natively since 3.7.11.
         *
         * {@see http://www.sqlite.org/releaselog/3_7_11.html}
         */
        $this->db->open();

        if (version_compare($this->db->getServerVersion(), '3.7.11', '>=')) {
            return parent::batchInsert($table, $columns, $rows, $params);
        }

        $schema = $this->db->getSchema();

        if (($tableSchema = $schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->getColumns();
        } else {
            $columnSchemas = [];
        }

        $values = [];

        /** @psalm-var array<array-key, array<array-key, string>>|Generator $rows */
        foreach ($rows as $row) {
            $vs = [];
            foreach ($row as $i => $value) {
                if (isset($columnSchemas[$columns[$i]])) {
                    /** @var scalar|null */
                    $value = $columnSchemas[$columns[$i]]->dbTypecast($value);
                }
                if (is_string($value)) {
                    $value = $this->db->getQuoter()->quoteValue($value);
                } elseif (is_float($value)) {
                    /** ensure type cast always has . as decimal separator in all locales */
                    $value = NumericHelper::normalize($value);
                } elseif ($value === false) {
                    $value = 0;
                } elseif ($value === null) {
                    $value = 'NULL';
                } elseif ($value instanceof ExpressionInterface) {
                    $value = $this->buildExpression($value, $params);
                }
                $vs[] = $value;
            }
            $values[] = implode(', ', $vs);
        }

        if (empty($values)) {
            return '';
        }

        /** @psalm-var array<array-key, string> $columns */
        foreach ($columns as $i => $name) {
            $columns[$i] = $this->db->getQuoter()->quoteColumnName($name);
        }

        return 'INSERT INTO ' . $this->db->getQuoter()->quoteTableName($table)
        . ' (' . implode(', ', $columns) . ') SELECT ' . implode(' UNION SELECT ', $values);
    }

    /**
     * Enables or disables integrity check.
     *
     * @param bool $check whether to turn on or off the integrity check.
     * @param string $schema the schema of the tables. Meaningless for SQLite.
     * @param string $table the table name. Meaningless for SQLite.
     *
     * @return string the SQL statement for checking integrity.
     */
    public function checkIntegrity(string $schema = '', string $table = '', bool $check = true): string
    {
        return 'PRAGMA foreign_keys=' . (int) $check;
    }

    /**
     * Builds a SQL statement for truncating a DB table.
     *
     * @param string $table the table to be truncated. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for truncating a DB table.
     */
    public function truncateTable(string $table): string
    {
        return 'DELETE FROM ' . $this->db->getQuoter()->quoteTableName($table);
    }

    /**
     * Builds a SQL statement for dropping an index.
     *
     * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for dropping an index.
     */
    public function dropIndex(string $name, string $table): string
    {
        return 'DROP INDEX ' . $this->db->getQuoter()->quoteTableName($name);
    }

    /**
     * Builds a SQL statement for dropping a DB column.
     *
     * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
     * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
     *
     * @throws NotSupportedException this is not supported by SQLite.
     *
     * @return string the SQL statement for dropping a DB column.
     */
    public function dropColumn(string $table, string $column): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for renaming a column.
     *
     * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
     * @param string $oldName the old name of the column. The name will be properly quoted by the method.
     * @param string $newName the new name of the column. The name will be properly quoted by the method.
     *
     * @throws NotSupportedException this is not supported by SQLite.
     *
     * @return string the SQL statement for renaming a DB column.
     */
    public function renameColumn(string $table, string $oldName, string $newName): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for adding a foreign key constraint to an existing table.
     * The method will properly quote the table and column names.
     *
     * @param string $name the name of the foreign key constraint.
     * @param string $table the table that the foreign key constraint will be added to.
     * @param array|string $columns the name of the column to that the constraint will be added on.
     * If there are multiple columns, separate them with commas or use an array to represent them.
     * @param string $refTable the table that the foreign key references to.
     * @param array|string $refColumns the name of the column that the foreign key references to.
     * If there are multiple columns, separate them with commas or use an array to represent them.
     * @param string|null $delete the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION,
     * SET DEFAULT, SET NULL.
     * @param string|null $update the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION,
     * SET DEFAULT, SET NULL.
     *
     * @return string the SQL statement for adding a foreign key constraint to an existing table.
     *@throws NotSupportedException this is not supported by SQLite
     *
     */
    public function addForeignKey(
        string $name,
        string $table,
        array|string $columns,
        string $refTable,
        array|string $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): string {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for dropping a foreign key constraint.
     *
     * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by
     * the method.
     * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
     *
     * @throws NotSupportedException this is not supported by SQLite
     *
     * @return string the SQL statement for dropping a foreign key constraint.
     */
    public function dropForeignKey(string $name, string $table): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for renaming a DB table.
     *
     * @param string $oldName the table to be renamed. The name will be properly quoted by the method.
     * @param string $newName the new table name. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB table.
     */
    public function renameTable(string $oldName, string $newName): string
    {
        return 'ALTER TABLE ' .
            $this->db->getQuoter()->quoteTableName($oldName) .
            ' RENAME TO ' .
            $this->db->getQuoter()->quoteTableName($newName);
    }

    /**
     * Builds a SQL statement for changing the definition of a column.
     *
     * @param string $table the table whose column is to be changed. The table name will be properly quoted by the
     * method.
     * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $type the new column type. The [[getColumnType()]] method will be invoked to convert abstract
     * column type (if any) into the physical one. Anything that is not recognized as abstract type will be kept in the
     * generated SQL. For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become
     * 'varchar(255) not null'.
     *
     * @throws NotSupportedException this is not supported by SQLite.
     *
     * @return string the SQL statement for changing the definition of a column.
     */
    public function alterColumn(string $table, string $column, string $type): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for adding a primary key constraint to an existing table.
     *
     * @param string $name the name of the primary key constraint.
     * @param string $table the table that the primary key constraint will be added to.
     * @param array|string $columns comma separated string or array of columns that the primary key will consist of.
     *
     * @return string the SQL statement for adding a primary key constraint to an existing table.
     *@throws Exception
     *
     */
    public function addPrimaryKey(string $name, string $table, array|string $columns): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Builds a SQL statement for removing a primary key constraint to an existing table.
     *
     * @param string $name the name of the primary key constraint to be removed.
     * @param string $table the table that the primary key constraint will be removed from.
     *
     * @throws NotSupportedException this is not supported by SQLite.
     *
     * @return string the SQL statement for removing a primary key constraint from an existing table.
     */
    public function dropPrimaryKey(string $name, string $table): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Creates a SQL command for adding a unique constraint to an existing table.
     *
     * @param string $name the name of the unique constraint. The name will be properly quoted by the method.
     * @param string $table the table that the unique constraint will be added to. The name will be properly quoted by
     * the method.
     * @param array|string $columns the name of the column to that the constraint will be added on. If there are
     * multiple columns, separate them with commas. The name will be properly quoted by the method.
     *
     * @throws Exception
     *
     * @return string the SQL statement for adding a unique constraint to an existing table.
     */
    public function addUnique(string $name, string $table, $columns): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * Creates a SQL command for dropping a unique constraint.
     *
     * @param string $name the name of the unique constraint to be dropped. The name will be properly quoted by the
     * method.
     * @param string $table the table whose unique constraint is to be dropped. The name will be properly quoted by the
     * method.
     *
     * @throws NotSupportedException
     *
     * @return string the SQL statement for dropping an unique constraint.
     */
    public function dropUnique(string $name, string $table): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by SQLite.');
    }

    /**
     * @param int|Expression|null $limit
     * @param int|Expression|null $offset
     *
     * @return string the LIMIT and OFFSET clauses.
     */
    public function buildLimit(Expression|int|null $limit, Expression|int|null $offset): string
    {
        $sql = '';

        if ($this->hasLimit($limit)) {
            $sql = "LIMIT $limit";
            if ($this->hasOffset($offset)) {
                $sql .= " OFFSET $offset";
            }
        } elseif ($this->hasOffset($offset)) {
            /**
             * limit is not optional in SQLite.
             *
             * {@see http://www.sqlite.org/syntaxdiagrams.html#select-stmt}
             */
            $sql = "LIMIT 9223372036854775807 OFFSET $offset"; // 2^63-1
        }

        return $sql;
    }

    /**
     * Generates a SELECT SQL statement from a {@see Query} object.
     *
     * @param Query $query the {@see Query} object from which the SQL statement will be generated.
     * @param array $params the parameters to be bound to the generated SQL statement. These parameters will be included
     * in the result with the additional parameters generated during the query building process.
     *
     * @throws Exception|InvalidArgumentException|InvalidConfigException|NotSupportedException
     *
     * @return array the generated SQL statement (the first array element) and the corresponding parameters to be bound
     * to the SQL statement (the second array element). The parameters returned include those provided in `$params`.
     * @psalm-return array{string, array}
     */
    public function build(Query $query, array $params = []): array
    {
        $query = $query->prepare($this);

        $params = empty($params) ? $query->getParams() : array_merge($params, $query->getParams());

        $clauses = [
            $this->buildSelect($query->getSelect(), $params, $query->getDistinct(), $query->getSelectOption()),
            $this->buildFrom($query->getFrom(), $params),
            $this->buildJoin($query->getJoin(), $params),
            $this->buildWhere($query->getWhere(), $params),
            $this->buildGroupBy($query->getGroupBy()),
            $this->buildHaving($query->getHaving(), $params),
        ];

        $sql = implode($this->separator, array_filter($clauses));
        $sql = $this->buildOrderByAndLimit($sql, $query->getOrderBy(), $query->getLimit(), $query->getOffset());

        if (!empty($query->getOrderBy())) {
            foreach ($query->getOrderBy() as $expression) {
                if ($expression instanceof ExpressionInterface) {
                    $this->buildExpression($expression, $params);
                }
            }
        }

        if (!empty($query->getGroupBy())) {
            foreach ($query->getGroupBy() as $expression) {
                if ($expression instanceof ExpressionInterface) {
                    $this->buildExpression($expression, $params);
                }
            }
        }

        $union = $this->buildUnion($query->getUnion(), $params);

        if ($union !== '') {
            $sql = "$sql$this->separator$union";
        }

        $with = $this->buildWithQueries($query->getWithQueries(), $params);

        if ($with !== '') {
            $sql = "$with$this->separator$sql";
        }

        return [$sql, $params];
    }

    /**
     * Builds a SQL statement for creating a new index.
     *
     * @param string $name the name of the index. The name will be properly quoted by the method.
     * @param string $table the table that the new index will be created for. The table name will be properly quoted by
     * the method.
     * @param array|string $columns the column(s) that should be included in the index. If there are multiple columns,
     * separate them with commas or use an array to represent them. Each column name will be properly quoted by the
     * method, unless a parenthesis is found in the name.
     * @param bool $unique whether to add UNIQUE constraint on the created index.
     *
     * @throws Exception|InvalidArgumentException
     *
     * @return string the SQL statement for creating a new index.
     *
     * @psalm-param array<array-key, ExpressionInterface|string>|string $columns
     */
    public function createIndex(string $name, string $table, $columns, bool $unique = false): string
    {
        $tableParts = explode('.', $table);

        $schema = null;
        if (count($tableParts) === 2) {
            [$schema, $table] = $tableParts;
        }

        return ($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
            . $this->db->getQuoter()->quoteTableName(($schema ? $schema . '.' : '') . $name) . ' ON '
            . $this->db->getQuoter()->quoteTableName($table)
            . ' (' . $this->buildColumns($columns) . ')';
    }

    /**
     * @param array $unions
     * @param array $params the binding parameters to be populated.
     *
     * @throws Exception|InvalidArgumentException|InvalidConfigException|NotSupportedException
     *
     * @return string the UNION clause built from {@see Query::$union}.
     *
     * @psalm-param array<array{query:Query|string, all:bool}> $unions
     */
    public function buildUnion(array $unions, array &$params = []): string
    {
        if (empty($unions)) {
            return '';
        }

        $result = '';

        foreach ($unions as $i => $union) {
            $query = $union['query'];
            if ($query instanceof Query) {
                [$unions[$i]['query'], $params] = $this->build($query, $params);
            }

            $result .= ' UNION ' . ($union['all'] ? 'ALL ' : '') . ' ' . $unions[$i]['query'];
        }

        return trim($result);
    }

    /**
     * Contains array of default expression builders. Extend this method and override it, if you want to change default
     * expression builders for this query builder.
     *
     * @return array
     *
     * See {@see ExpressionBuilder} docs for details.
     */
    protected function defaultExpressionBuilders(): array
    {
        return array_merge(parent::defaultExpressionBuilders(), [
            LikeCondition::class => LikeConditionBuilder::class,
            InCondition::class => InConditionBuilder::class,
        ]);
    }
}
