<?php


namespace crystlbrd\Architect\Blueprints;


class ConnectorEntity extends Entity
{
    public function add(Entity $entity)
    {
        $Origin = $this->getOriginModel($entity);
    }

    protected function getOriginModel(Entity $target)
    {

    }
}