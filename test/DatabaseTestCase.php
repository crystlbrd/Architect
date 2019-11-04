<?php

namespace crystlbrd\Architect\Test;

use crystlbrd\DatabaseHandler\Connections\MySQLConnection;
use crystlbrd\DatabaseHandler\DatabaseHandler;
use PHPUnit\Framework\TestCase;

class DatabaseTestCase extends TestCase
{
    protected function getConnection()
    {
        $dbh = new DatabaseHandler();
        $conn = $this->createStub(MySQLConnection::class);

        $dbh->addConnection('test', $conn);
        return $dbh;
    }
}