<?php


namespace crystlbrd\Architect\Test\Units;


use crystlbrd\Architect\Test\DatabaseTestCase;
use crystlbrd\Architect\Test\TestConnector;

class ConnectorTest extends DatabaseTestCase
{
    public function testConnections()
    {
        $ModelA = new TestConnector($this->getConnection());

        var_dump($ModelA->refB);
    }
}