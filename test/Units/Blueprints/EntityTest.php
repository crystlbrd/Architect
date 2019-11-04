<?php

namespace crystlbrd\Architect\Test\Units;

use crystlbrd\Architect\Test\DatabaseTestCase;
use crystlbrd\Architect\Test\TestModel;

class EntityTest extends DatabaseTestCase
{
    public function testInit() {
        $model = new TestModel($this->getConnection());
        self::assertFalse($model->isLoaded());
    }
}