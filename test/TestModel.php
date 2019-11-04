<?php


namespace crystlbrd\Architect\Test;


use crystlbrd\Architect\Blueprints\Entity;

class TestModel extends Entity
{
    protected $TableName = 'testtable';
    protected $Columns = [
        'col1' => [],
        'col2' => []
    ];
}