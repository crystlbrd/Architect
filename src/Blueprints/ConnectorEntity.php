<?php


namespace crystlbrd\Architect\Blueprints;


use Countable;
use crystlbrd\DatabaseHandler\DatabaseHandler;
use Exception;
use Iterator;

abstract class ConnectorEntity extends Entity implements Iterator, Countable
{
    /**
     * @var Entity
     */
    protected $OriginEntity;

    /**
     * @var string string
     */
    protected $OriginRefColumn;

    /**
     * @var string
     */
    protected $TargetRefColumn;

    /**
     * @var string
     */
    protected $TargetClass;

    /**
     * @var array
     */
    protected $Filter = [];

    /**
     * @var array
     */
    protected $List = [];

    /**
     * @var int
     */
    protected $Pointer = 0;

    /**
     * ConnectorEntity constructor.
     * @param DatabaseHandler $Connection
     * @param Entity $Origin
     * @param string $originRefColumn
     * @throws Exception
     */
    public function __construct(DatabaseHandler $Connection, Entity $Origin, string $originRefColumn)
    {
        // Build model
        parent::__construct($Connection);

        // init
        $this->OriginEntity = $Origin;
        $this->OriginRefColumn = $originRefColumn;

        // determine the connection partner
        $config = $this->getTarget($Origin);

        $this->TargetClass = $config['to'];
        $this->TargetRefColumn = $config['column'];

        // load connected entities
        $this->loadConnectedEntities();
    }

    /**
     * Determines the connection partner
     * @param Entity $entity
     * @return array
     * @throws Exception
     */
    protected function getTarget(Entity $entity): array
    {
        // Check, if there is exactly two connections
        if (count($this->ConnectedEntities) == 2) {
            foreach ($this->ConnectedEntities as $column => $config) {
                if ($config['to'] != get_class($entity)) {
                    return array_merge($config, [
                        'column' => $column
                    ]);
                }
            }

            return [];
        } else {
            throw new Exception('Request too ambiguous! There are not two connections configured.');
        }
    }

    /**
     * Checks, if an entity is connected
     * @param Entity $entity
     * @return bool
     * @throws Exception
     */
    public function has(Entity $entity): bool
    {
        return (isset($this->List[$entity->getPrimaryValue()]));
    }

    /**
     * Adds an entity to cache
     * @param int $cid
     * @param Entity $entity
     * @throws Exception
     */
    protected function addToList(int $cid, Entity $entity)
    {
        $this->List[$entity->getPrimaryValue()] = [
            'cid' => $cid,
            'model' => $entity
        ];
    }

    protected function removeFromList(Entity $entity)
    {
        unset($this->List[$entity->getPrimaryValue()]);
    }

    /**
     * Adds an entity to the connector
     * @param Entity $entity
     * @param bool $allowDoubles
     * @return bool
     * @throws Exception
     */
    public function add(Entity $entity, bool $allowDoubles = false): bool
    {
        if ($allowDoubles || !$this->has($entity)) {
            $id = $this->insert(array_merge([
                $this->OriginRefColumn => $this->OriginEntity->getPrimaryValue(),
                $this->TargetRefColumn => $entity->getPrimaryValue()
            ], $this->Filter));

            if ($id) {
                $this->addToList($id, $entity);
                return true;
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes an entity from the connector
     * @param Entity $entity
     * @return bool
     * @throws Exception
     */
    public function remove(Entity $entity): bool
    {
        if ($this->has($entity)) {
            $cid = $this->List[$entity->getPrimaryValue()]['cid'];

            if ($this->Table->delete(
                [
                    'and' => array_merge(
                        [
                            $this->getPrimaryColumn() => $cid
                        ],
                        $this->Filter
                    )
                ]
            )) {
                $this->removeFromList($entity);
                return true;
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Updates the complete list. Adds new entities and removes missing
     * @param array $newList
     * @return bool
     * @throws Exception
     */
    public function updateList(array $newList): bool
    {
        $cache = [];

        // add all new items
        foreach ($newList as $newEntity) {
            if ($newEntity instanceof Entity) {
                // save the entity to cache
                $cache[] = $newEntity->getPrimaryValue();

                // check if the tag is not already added
                if (!$this->has($newEntity)) {
                    // try to add
                    if (!$this->add($newEntity)) return false;
                }
            } else {
                throw new Exception('Malformed list! Items are not entities!');
            }
        }

        // delete all missing items
        foreach ($this->List as $item) {
            // check, if the item is not in the new list
            if (!in_array($item['model']->getPrimaryValue(), $cache)) {
                // try to remove
                if (!$this->remove($item['model'])) return false;
            }
        }

        // all went fine - return true
        return true;
    }

    protected function loadConnectedEntities()
    {
        // select all entities
        $res = $this->Table->select(
            [   // SELECT
                $this->getPrimaryColumn(), $this->TargetRefColumn
            ],
            [   // WHERE
                'and' => array_merge([
                    $this->OriginRefColumn => $this->OriginEntity->getPrimaryValue()
                ], $this->Filter)
            ]
        );

        if ($res) {
            foreach ($res as $r) {
                $idCol = $this->getPrimaryColumn();
                $targetCol = $this->TargetRefColumn;

                $this->addToList($r->$idCol, new $this->TargetClass($this->Connection, $r->$targetCol));
            }
        }
    }

    public function setFilter(array $filter, bool $updateList = true): void
    {
        $this->Filter = $filter;

        if ($updateList) {
            $this->loadConnectedEntities();
        }
    }

    /**
     * Filters the list and returns only the selected parameters
     * @param array $properties
     * @param bool $flat Reduce output array to just one level (only possible with one property)
     * @return array
     */
    public function filter(array $properties = [], bool $flat = false)
    {
        $items = [];
        $i = 0;

        foreach ($this->List as $item) {
            $entity = $item['model'];
            foreach ($properties as $prop) {
                if (isset($entity->$prop)) {
                    if (count($properties) == 1 && $flat) {
                        $items[] = $entity[$prop];
                    } else {
                        $items[$i][$prop] = $entity->$prop;
                    }
                }
            }

            $i++;
        }

        return $items;
    }

    ### ITERATOR ###

    /**
     * Return the current element
     * @link https://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current()
    {
        return array_values($this->List)[$this->Pointer];
    }

    /**
     * Move forward to next element
     * @link https://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next()
    {
        $this->Pointer++;
    }

    /**
     * Return the key of the current element
     * @link https://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key()
    {
        return $this->Pointer;
    }

    /**
     * Checks if current position is valid
     * @link https://php.net/manual/en/iterator.valid.php
     * @return bool The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid()
    {
        return $this->count() < $this->Pointer;
    }

    /**
     * Rewind the Iterator to the first element
     * @link https://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind()
    {
        $this->Pointer = 0;
    }


    ### COUNTABLE ###

    /**
     * Count elements of an object
     * @link https://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        return count($this->List);
    }
}