<?php

declare(strict_types=1);

namespace Yiisoft\Db\Sqlite\Tests;

use Closure;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryBuilderInterface;
use Yiisoft\Db\Sqlite\PDO\QueryBuilderPDOSqlite;
use Yiisoft\Db\Sqlite\PDO\SchemaPDOSqlite;
use Yiisoft\Db\TestSupport\TestQueryBuilderTrait;
use Yiisoft\Db\TestSupport\TraversableObject;

/**
 * @group sqlite
 */
final class QueryBuilderTest extends TestCase
{
    use TestQueryBuilderTrait;

    protected string $likeEscapeCharSql = " ESCAPE '\\'";

    /**
     * @return QueryBuilderInterface
     */
    protected function getQueryBuilder(ConnectionInterface $db): QueryBuilderInterface
    {
        return new QueryBuilderPDOSqlite($this->getConnection($db));
    }

    public function testBuildUnion(): void
    {
        $db = $this->getConnection();

        $expectedQuerySql = $this->replaceQuotes(
            'SELECT `id` FROM `TotalExample` `t1` WHERE (w > 0) AND (x < 2) UNION  SELECT `id`'
            . ' FROM `TotalTotalExample` `t2` WHERE w > 5 UNION ALL  SELECT `id`'
            . ' FROM `TotalTotalExample` `t3` WHERE w = 3'
        );

        $query = new Query($db);

        $secondQuery = new Query($db);
        $secondQuery->select('id')->from('TotalTotalExample t2')->where('w > 5');

        $thirdQuery = new Query($db);
        $thirdQuery->select('id')->from('TotalTotalExample t3')->where('w = 3');

        $query->select('id')
            ->from('TotalExample t1')
            ->where(['and', 'w > 0', 'x < 2'])
            ->union($secondQuery)
            ->union($thirdQuery, true);

        [$actualQuerySql, $queryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals([], $queryParams);
    }

    public function testBuildWithQuery()
    {
        $db = $this->getConnection();

        $expectedQuerySql = $this->replaceQuotes(
            'WITH a1 AS (SELECT [[id]] FROM [[t1]] WHERE expr = 1), a2 AS (SELECT [[id]] FROM [[t2]]'
            . ' INNER JOIN [[a1]] ON t2.id = a1.id WHERE expr = 2 UNION  SELECT [[id]] FROM [[t3]] WHERE expr = 3)'
            . ' SELECT * FROM [[a2]]'
        );

        $with1Query = (new Query($db))->select('id')->from('t1')->where('expr = 1');
        $with2Query = (new Query($db))->select('id')->from('t2')->innerJoin('a1', 't2.id = a1.id')->where('expr = 2');
        $with3Query = (new Query($db))->select('id')->from('t3')->where('expr = 3');

        $query = (new Query($db))
            ->withQuery($with1Query, 'a1')
            ->withQuery($with2Query->union($with3Query), 'a2')
            ->from('a2');

        [$actualQuerySql, $queryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals([], $queryParams);
    }

    public function testRenameTable()
    {
        $db = $this->getConnection();
        $sql = $this->getQueryBuilder($db)->renameTable('table_from', 'table_to');
        $this->assertEquals('ALTER TABLE `table_from` RENAME TO `table_to`', $sql);
    }

    public function testAddDropForeignKey(): void
    {
        $this->markTestSkipped('Adding/dropping foreign keys is not supported in SQLite.');
    }

    public function batchInsertProvider(): array
    {
        $data = $this->batchInsertProviderTrait();
        $data['escape-danger-chars']['expected'] = 'INSERT INTO `customer` (`address`)'
            . " VALUES ('SQL-danger chars are escaped: ''); --')";
        return $data;
    }

    /**
     * @dataProvider batchInsertProvider
     *
     * @param string $table
     * @param array $columns
     * @param array $value
     * @param string $expected
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBatchInsert(string $table, array $columns, array $value, string $expected): void
    {
        $db = $this->getConnection();
        $queryBuilder = $this->getQueryBuilder($db);
        $sql = $queryBuilder->batchInsert($table, $columns, $value);
        $this->assertEquals($expected, $sql);
    }

    public function buildConditionsProvider(): array
    {
        return array_merge($this->buildConditionsProviderTrait(), [
            'composite in using array objects' => [
                ['in', new TraversableObject(['id', 'name']), new TraversableObject([
                    ['id' => 1, 'name' => 'oy'],
                    ['id' => 2, 'name' => 'yo'],
                ])],
                '(([[id]] = :qp0 AND [[name]] = :qp1) OR ([[id]] = :qp2 AND [[name]] = :qp3))',
                [':qp0' => 1, ':qp1' => 'oy', ':qp2' => 2, ':qp3' => 'yo'],
            ],
            'composite in' => [
                ['in', ['id', 'name'], [['id' => 1, 'name' => 'oy']]],
                '(([[id]] = :qp0 AND [[name]] = :qp1))',
                [':qp0' => 1, ':qp1' => 'oy'],
            ],
        ]);
    }

    /**
     * @dataProvider buildConditionsProvider
     *
     * @param array|ExpressionInterface $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildFilterConditionProviderTrait
     *
     * @param array $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildFilterCondition(array $condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->filterWhere($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    public function indexesProvider(): array
    {
        $result = parent::indexesProvider();
        $result['drop'][0] = 'DROP INDEX [[CN_constraints_2_single]]';
        $indexName = 'myindex';
        $schemaName = 'myschema';
        $tableName = 'mytable';
        $result['with schema'] = [
            "CREATE INDEX {{{$schemaName}}}.[[$indexName]] ON {{{$tableName}}} ([[C_index_1]])",
            function (QueryBuilder $qb) use ($tableName, $indexName, $schemaName) {
                return $qb->createIndex($indexName, $schemaName . '.' . $tableName, 'C_index_1');
            },
        ];
        return $result;
    }

    /**
     * @dataProvider buildFromDataProviderTrait
     *
     * @param string $table
     * @param string $expected
     *
     * @throws Exception
     */
    public function testBuildFrom(string $table, string $expected): void
    {
        $db = $this->getConnection();
        $params = [];
        $sql = $this->getQueryBuilder($db)->buildFrom([$table], $params);
        $this->assertEquals('FROM ' . $this->replaceQuotes($expected), $sql);
    }

    /**
     * @dataProvider buildLikeConditionsProviderTrait
     *
     * @param array|object $condition
     * @param string $expected
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildLikeCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildExistsParamsProviderTrait
     *
     * @param string $cond
     * @param string $expectedQuerySql
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testBuildWhereExists(string $cond, string $expectedQuerySql): void
    {
        $db = $this->getConnection();
        $expectedQueryParams = [];
        $subQuery = new Query($db);
        $subQuery->select('1')->from('Website w');
        $query = new Query($db);
        $query->select('id')->from('TotalExample t')->where([$cond, $subQuery]);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    public function createDropIndexesProvider(): array
    {
        $result = $this->createDropIndexesProviderTrait();
        $result['drop'][0] = 'DROP INDEX [[CN_constraints_2_single]]';
        $indexName = 'myindex';
        $schemaName = 'myschema';
        $tableName = 'mytable';
        $result['with schema'] = [
            "CREATE INDEX {{{$schemaName}}}.[[$indexName]] ON {{{$tableName}}} ([[C_index_1]])",
            function (QueryBuilderInterface $qb) use ($tableName, $indexName, $schemaName) {
                return $qb->createIndex($indexName, $schemaName . '.' . $tableName, 'C_index_1');
            },
        ];
        return $result;
    }

    /**
     * @dataProvider createDropIndexesProvider
     *
     * @param string $sql
     */
    public function testCreateDropIndex(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider deleteProviderTrait
     *
     * @param string $table
     * @param array|string $condition
     * @param string $expectedSQL
     * @param array $expectedParams
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function testDelete(string $table, $condition, string $expectedSQL, array $expectedParams): void
    {
        $db = $this->getConnection();
        $actualParams = [];
        $actualSQL = $this->getQueryBuilder($db)->delete($table, $condition, $actualParams);
        $this->assertSame($expectedSQL, $actualSQL);
        $this->assertSame($expectedParams, $actualParams);
    }

    public function testCreateTableColumnTypes(): void
    {
        $db = $this->getConnection(true);
        $qb = $this->getQueryBuilder($db);

        if ($db->getTableSchema('column_type_table', true) !== null) {
            $db->createCommand($qb->dropTable('column_type_table'))->execute();
        }

        $columns = [];
        $i = 0;

        foreach ($this->columnTypes() as [$column, $builder, $expected]) {
            if (
                !(
                    strncmp($column, SchemaPDOSqlite::TYPE_PK, 2) === 0 ||
                    strncmp($column, SchemaPDOSqlite::TYPE_UPK, 3) === 0 ||
                    strncmp($column, SchemaPDOSqlite::TYPE_BIGPK, 5) === 0 ||
                    strncmp($column, SchemaPDOSqlite::TYPE_UBIGPK, 6) === 0 ||
                    strncmp(substr($column, -5), 'FIRST', 5) === 0
                )
            ) {
                $columns['col' . ++$i] = str_replace('CHECK (value', 'CHECK ([[col' . $i . ']]', $column);
            }
        }

        $db->createCommand($qb->createTable('column_type_table', $columns))->execute();
        $this->assertNotEmpty($db->getTableSchema('column_type_table', true));
    }
}
