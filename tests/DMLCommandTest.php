<?php

declare(strict_types=1);

namespace Yiisoft\Db\Sqlite\Tests;

use Yiisoft\Db\Sqlite\DMLCommand;

/**
 * @group sqlite
 */
final class DMLCommandTest extends TestCase
{
    public function testResetSequence(): void
    {
        $db = $this->getConnection(true);
        $dml = new DMLCommand($db->createCommand(), $db->getQuoter(), $db->getSchema());

        $expected = "UPDATE sqlite_sequence SET seq='5' WHERE name='item'";
        $sql = $dml->resetSequence('item');
        $this->assertEquals($expected, $sql);

        $expected = "UPDATE sqlite_sequence SET seq='3' WHERE name='item'";
        $sql = $dml->resetSequence('item', 4);
        $this->assertEquals($expected, $sql);
    }
}
