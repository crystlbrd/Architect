<?php


namespace crystlbrd\Architect;


use Countable;
use crystlbrd\Architect\Blueprints\Entity;
use crystlbrd\DatabaseHandler\DatabaseHandler;
use crystlbrd\DatabaseHandler\Table;
use Exception;
use Iterator;

class EntityList implements Iterator, Countable
{
    /**
     * @var DatabaseHandler
     */
    protected $DatabaseHandler;

    /**
     * @var string
     */
    protected $Type;

    /**
     * @var Entity
     */
    protected $OriginModel;

    /**
     * @var Entity
     */
    protected $TargetModel;

    /**
     * @var string
     */
    protected $TargetClass;

    /**
     * @var Table
     */
    protected $TargetTable;

    /**
     * @var string
     */
    protected $TargetOnColumn;

    /**
     * @var array
     */
    protected $List = [];

    /**
     * @var int
     */
    protected $Pointer = 0;

    /**
     * EntityList constructor.
     * @param DatabaseHandler $dbh
     * @param Entity $origin
     * @param string $target
     * @param string $onColumn
     * @param string $type
     * @throws Exception
     */
    public function __construct(DatabaseHandler $dbh, Entity $origin, string $target, string $onColumn, string $type)
    {
        // init
        $this->DatabaseHandler = $dbh;
        $this->OriginModel = $origin;
        $this->TargetClass = $target;
        $this->TargetOnColumn = $onColumn;
        $this->Type = $type;

        $this->TargetModel = new $target($this->DatabaseHandler);
        $this->TargetTable = $this->TargetModel->getTable();

        // load list
        $this->loadConnections();
    }

    /**
     * Loads all connected entities
     * @throws Exception
     */
    public function loadConnections(): void
    {
        // unload previous entries
        $this->unload();

        // look for connected entries
        $pk = $this->TargetTable->getPrimaryColumn();
        $res = $this->TargetTable->select(
            [   // SELECT
                $pk
            ],
            [   // WHERE
                'and' => [
                    $this->TargetOnColumn => $this->OriginModel->getPrimaryValue()
                ]
            ]
        );

        if ($res) {
            foreach ($res as $r) {
                $entity = new $this->TargetClass($this->DatabaseHandler, $r->$pk);
                $this->addToList($entity);
            }
        }
    }

    /**
     * Unloads all currently loaded entities
     */
    public function unload(): void
    {
        $this->List = [];
    }

    /**
     * Adds an entity to the list
     * @param Entity $entity
     * @throws Exception
     */
    protected function addToList(Entity $entity): void
    {
        $this->List[$entity->getPrimaryValue()] = $entity;
    }

    /**
     * Returns if an entity is in the list
     * @param Entity $entity
     * @return bool
     * @throws Exception
     */
    public function has(Entity $entity)
    {
        return (isset($this->List[$entity->getPrimaryValue()]));
    }

    /**
     * Returns the current entity and counts up
     * @return bool|mixed
     */
    public function fetch()
    {
        if ($this->valid()) {
            // get the current
            $current = $this->current();

            // count one up
            $this->next();

            return $current;
        } else {
            return false;
        }
    }

    /**
     * Returns the full list
     * @return array
     */
    public function all()
    {
        return $this->List;
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

        foreach ($this->List as $entity) {
            foreach ($properties as $prop) {
                if ($entity->$prop !== null) {
                    if (count($properties) == 1 && $flat) {
                        $items[] = $entity->$prop;
                    } else {
                        $items[$i][$prop] = $entity->$prop;
                    }
                }
            }

            $i++;
        }

        return $items;
    }

    /**
     * Finds Entity by conditions. All conditions have to be true
     * @param array $conditions
     * @return array
     */
    public function find(array $conditions)
    {
        $items = [];

        foreach ($this->List as $Entity) {
            $items[$Entity->getPrimaryValue()] = $Entity;
            foreach ($conditions as $prop => $value) {
                if ($Entity->$prop !== $value) {
                    unset($items[$Entity->getPrimaryValue()]);
                    break;
                }
            }
        }

        return $items;
    }


    /*** ITERATOR ***/

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
        return ($this->Pointer < $this->count());
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


    /*** COUNTABLE ***/

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