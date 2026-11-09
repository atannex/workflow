<?php

namespace Atannex\Workflow\Tests\Unit;

use Atannex\Workflow\Support\Connections;
use Atannex\Workflow\Tests\TestCase;

class ConnectionsTest extends TestCase
{
    public function test_two_names_for_one_database_are_covered_once(): void
    {
        config([
            'database.connections.shop' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'atannex'],
            'database.connections.shop_alias' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'atannex'],
            'workflow.follow_subject_connection' => true,
            'workflow.connections' => ['shop', 'shop_alias'],
        ]);

        $this->assertSame([null, 'shop'], Connections::all());
    }
}
