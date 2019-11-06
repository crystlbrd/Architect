<?php


namespace crystlbrd\Architect\Test;


use crystlbrd\Architect\Blueprints\ConnectorEntity;

class TestConnector extends ConnectorEntity
{
    protected $TableName = 'c_ab';
    protected $Columns = [
        'id' => [],
        'refA' => [
            'connection' => [
                'type' => 'n:1',
                'to' => TestModel::class
            ]
        ],
        'refB' => [
            'connection' => [
                'type' => 'n:1',
                'to' => TestModel::class
            ]
        ]
    ];
}