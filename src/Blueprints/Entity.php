<?php


namespace crystlbrd\Architect\Blueprints;


use crystlbrd\Architect\EntityList;
use crystlbrd\DatabaseHandler\DatabaseHandler;
use crystlbrd\DatabaseHandler\Entry;
use crystlbrd\DatabaseHandler\Exceptions\EntryException;
use crystlbrd\DatabaseHandler\Table;
use Exception;

abstract class Entity
{
    /*** CONFIG ***/

    /**
     * @var string table name
     */
    protected $TableName;

    /**
     * @var array table structure
     */
    protected $Columns;


    /*** INTERNAL ATTRIBUTES ***/

    /**
     * All errors are displayed
     */
    const MODE_STRICT = 0;

    /**
     * warnings and notices are ignored
     */
    const MODE_SILENT = 1;

    const VALID_TYPES = [
        '1:1',
        '1:n',
        'n:1',
        'n:m'
    ];

    /// EXCEPTION CODES
    const EXCP_ENTITY_NOT_FOUND = 1001;
    const EXCP_CONDITIONS_TO_AMBIGUOUS = 1002;

    /**
     * @var DatabaseHandler
     */
    protected $Connection;

    /**
     * @var Table Table object
     */
    protected $Table;

    /**
     * @var string Primary Column (Primary Key)
     */
    protected $PrimaryColumn;

    /**
     * @var Entry Entry object
     */
    protected $Entry;

    /**
     * @var bool saves if an entry is loaded
     */
    protected $isLoaded = false;

    /**
     * @var array saves all connected Entities (foreign keys)
     */
    protected $ConnectedEntities = [];

    /**
     * @var int error display mode
     */
    public $Mode = self::MODE_SILENT;

    /**
     * @var array saves planned changes
     */
    protected $Changelist = [];


    /*** METHODS ***/

    /**
     * Entity constructor.
     * @param DatabaseHandler $Connection
     * @param mixed $resource Data to load entry from. Can be an Entry object, an array (conditions) or a string/int for an ID
     * @throws Exception
     */
    public function __construct(DatabaseHandler $Connection, $resource = null)
    {
        // check if model is correctly configured
        $this->validateConfig(true);

        // save connection for later use
        $this->Connection = $Connection;

        // load table
        $this->Table = $this->Connection->load($this->TableName);

        // execute custom setup
        $this->setup();

        // load resource if given
        if ($resource !== null) {
            if ($resource instanceof Entry) {
                // load by an Entry object
                $this->loadByEntry($resource);
            } else if (is_array($resource)) {
                // load by a condition array
                $this->loadByConditions($resource);
            } else {
                // load by an ID
                $this->loadById($resource);
            }
        }
    }

    /**
     * Is executed after the object is constructed, but before the resource is loaded
     */
    public function setup(): void
    {
        # do by default just nothing
    }

    /**
     * Validates the configuration
     * @param bool $throwException throw an exception if invalid?
     * @param array $validationRules additional rules
     * @return bool
     * @throws Exception if invalid (only if activated)
     */
    protected function validateConfig(bool $throwException = false, array $validationRules = []): bool
    {
        $validations = array_merge([
            [ // Tabellenname
                'valid' => (
                    $this->TableName !== null
                    && is_string($this->TableName)
                ),
                'msg' => 'TableName not defined or not a string!'
            ],
            [ // Spalten
                'valid' => (
                    $this->Columns !== null
                    && is_array($this->Columns)
                    && !empty($this->Columns)
                ),
                'msg' => 'Columns are not defined or empty!'
            ]
        ], $validationRules);

        foreach ($validations as $check) {
            if (!$check['valid']) {
                if ($throwException) {
                    throw new Exception($check['msg']);
                } else {
                    return false;
                }
            }
        }

        // parse and validate connections to other entities
        $this->parseConnections();

        return true;
    }

    /**
     * Empties changelist
     */
    public function clearChangelist(): void
    {
        $this->Changelist = [];
    }

    /**
     * Deletes cached instances of connected entities
     */
    public function unloadConnectedEntities(): void
    {
        foreach ($this->ConnectedEntities as $column => $config) {
            // Unset cached connections
            unset($this->ConnectedEntities[$column]['model']);
            unset($this->ConnectedEntities[$column]['list']);
            unset($this->ConnectedEntities[$column]['connector']);
        }
    }


    /*** DATA LOADING ***/

    /**
     * Loads an Entry by its ID
     * @param string|int $id Entry ID
     * @return bool
     * @throws Exception
     */
    public function loadById($id): bool
    {
        return $this->loadByConditions(['and' => [$this->getPrimaryColumn() => $id]]);
    }

    /**
     * Loads an Entry by an array of conditions
     * @param array $conditions (check crystlbrd/DatabaseHanlder -> IConnection::select())
     * @return bool
     * @throws Exception
     */
    public function loadByConditions(array $conditions): bool
    {
        return $this->loadByEntry($this->getEntryByConditions($conditions));
    }

    /**
     * Saves an Entry internally for later use
     * @param Entry $Entry
     * @return bool
     */
    public function loadByEntry(Entry $Entry): bool
    {
        // unload previous Entry
        $this->unload();

        // save Entry
        $this->Entry = $Entry;

        // mark as loaded
        $this->isLoaded = true;

        return true;
    }

    /**
     * Parses and validates configured connections to other entities
     * @throws Exception
     */
    protected function parseConnections(): void
    {
        foreach ($this->Columns as $column => $prop) {
            if (isset($prop['connection'])) {
                /// PARSE & VALIDATE

                // Type (required)
                if (isset($prop['connection']['type']) && in_array($prop['connection']['type'], self::VALID_TYPES)) {
                    $this->ConnectedEntities[$column]['type'] = $prop['connection']['type'];
                } else {
                    throw new Exception('Missing type of connection for ' . $column . '!');
                }

                // On column (required for n:m and 1:n connections)
                if (
                    $this->ConnectedEntities[$column]['type'] == 'n:m' or $this->ConnectedEntities[$column]['type'] == '1:n'
                    && isset($prop['connection']['on'])
                ) {
                    $this->ConnectedEntities[$column]['on'] = $prop['connection']['on'];
                } else if (
                    $this->ConnectedEntities[$column]['type'] == 'n:m'
                    || $this->ConnectedEntities[$column]['type'] == '1:n'
                ) {
                    throw new Exception('Missing referencing column (on) of the target entity for ' . $column . '!');
                }

                // Connected Entity (required)
                if (isset($prop['connection']['to'])) {
                    $this->ConnectedEntities[$column]['to'] = $prop['connection']['to'];
                } else {
                    throw new Exception('Missing reference to connected entity for ' . $column . '!');
                }

                // Alias (optional)
                if (isset($prop['alias'])) {
                    $this->ConnectedEntities[$column]['alias'] = $prop['alias'];
                }
            }
        }
    }

    /**
     * Loads an Entry from the data source by given conditions
     * @param array $conditions
     * @return Entry
     * @throws Exception
     */
    protected function getEntryByConditions(array $conditions): Entry
    {
        $Result = $this->Table->select([], $conditions);
        if (count($Result)) {
            if (count($Result) > 1 && $this->Mode === self::MODE_STRICT) {
                throw new Exception('Following conditions are too ambiguous : ' . json_encode($conditions), self::EXCP_CONDITIONS_TO_AMBIGUOUS);
            }

            // get the entry from the result and return it
            $Entry = $Result->fetch();
            return $Entry;

        } else {
            // no entry found
            throw new Exception('No entries found with following conditions: ' . json_encode($conditions), self::EXCP_ENTITY_NOT_FOUND);
        }
    }

    /**
     * Unloads the Entry
     */
    public function unload()
    {
        // delete entry
        $this->Entry = null;

        // delete cache
        $this->clearChangelist();

        // delete connected entities
        $this->unloadConnectedEntities();

        // update status
        $this->isLoaded = false;
    }

    /**
     * Checks, if an Entry is loaded
     * @return bool
     */
    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }


    /*** DATA MANIPULATION ***/

    /**
     * Returns all planed changes to the Entry
     * @return array
     */
    protected function fetchData()
    {
        return $this->Changelist;
    }

    /**
     * Saves changes to the Entry into the data source
     * @return bool|int
     * @throws EntryException
     * @throws Exception
     */
    public function save()
    {
        if ($this->isLoaded) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }

    /**
     * Updates the loaded Entry
     * @param array $data
     * @return bool
     * @throws EntryException
     * @throws Exception
     */
    public function update(array $data = []): bool
    {
        if ($this->isLoaded()) {
            if (empty($data)) {
                $data = $this->fetchData();
            }

            if ($this->Entry->update($data)) {
                $this->clearChangelist();
                return true;
            } else {
                return false;
            }
        } else {
            if ($this->Mode == self::MODE_STRICT) {
                throw new Exception('No entry loaded!');
            } else {
                return false;
            }
        }
    }

    /**
     * Inserts a new entry to the data source
     * @param array $data
     * @param bool $autoload
     * @return int ID of the new entry
     * @throws Exception
     */
    public function insert(array $data = [], bool $autoload = true): int
    {
        if (empty($data)) {
            $data = $this->fetchData();
        }

        if ($this->Table->insert($data)) {
            $this->clearChangelist();
            $id = $this->Table->getConnection()->getLastInsertId();
            if ($autoload) {
                return $this->loadById($id);
            } else {
                return $id;
            }
        } else {
            return 0;
        }
    }


    /*** GETTERS ***/

    /**
     * Returns the primary column (primary key) of the table
     * @return string
     * @throws Exception
     */
    public function getPrimaryColumn(): string
    {
        // is the primary column already found?
        if ($this->PrimaryColumn === null) {
            // look for it
            foreach ($this->Columns as $column => $properties) {
                if (isset($properties['isPrimary']) && $properties['isPrimary'] === true) {
                    $this->PrimaryColumn = $column;
                    break;
                }
            }

            // throw an exception on strict mode if none found
            if ($this->Mode === self::MODE_STRICT && $this->PrimaryColumn === null) {
                throw new Exception('No primary key defined!');
            }
        }

        // return the primary column
        return $this->PrimaryColumn;
    }

    /**
     * Returns the value of the primary column of the current loaded entry
     * @return Entry|null
     * @throws Exception (only in strict mode)
     */
    public function getPrimaryValue()
    {
        if ($this->isLoaded()) {
            $pk = $this->getPrimaryColumn();
            return $this->Entry->$pk;
        } else if ($this->Mode === self::MODE_STRICT) {
            throw new Exception('Can not get primary value! No entry loaded.');
        } else {
            return null;
        }
    }

    /**
     * Returns the table name
     * @return string
     */
    public function getTableName(): string
    {
        return $this->TableName;
    }

    /**
     * Returns the table object
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->Table;
    }

    /**
     * Returns the column name by its alias
     * @param string $alias
     * @return string|null
     * @throws Exception
     */
    public function getColumnNameByAlias(string $alias): ?string
    {
        foreach ($this->Columns as $column => $properties) {
            if (isset($properties['alias']) && $properties['alias'] === $alias) {
                return $column;
            }
        }

        // throw an exception on strict mode
        if ($this->Mode === self::MODE_STRICT) {
            throw new Exception('Could not find a column with the alias "' . $alias . '"!');
        } else {
            return null;
        }
    }

    /**
     * Gets a connected entity
     * @param $name
     * @return bool|mixed
     * @throws Exception
     */
    public function getConnectedEntity($name)
    {
        if (
            // Try to find the connection by its column name
            ($config = $this->getConnectedEntityByColumnName($name))
            // Try to find the connection by its alias
            || ($config = $this->getConnectedEntityByAlias($name))
        ) {
            $column = $config['column'];
            $model = $config['to'];

            switch ($config['type']) {
                case '1:1':
                case 'n:1':
                    // load model if missing
                    if (!isset($config['model'])) {
                        $this->ConnectedEntities[$column]['model'] = new $model($this->Connection, $this->Entry->$column);
                    }

                    return $this->ConnectedEntities[$column]['model'];
                    break;
                case '1:n':
                    // load list of missing
                    if (!isset($config['list'])) {
                        $this->ConnectedEntities[$column]['list'] = new EntityList($this->Connection, $this, $model, $config['on'], $config['type']);
                    }

                    return $this->ConnectedEntities[$column]['list'];
                    break;
                case 'n:m':
                    // load connector if missing
                    if (!isset($config['connector'])) {
                        $this->ConnectedEntities[$column]['connector'] = new $model($this->Connection, $this, $config['on']);
                    }

                    return $this->ConnectedEntities[$column]['connector'];
                    break;
                default:
                    throw new Exception('Invalid connection type!');
            }

        } else if ($this->Mode === self::MODE_STRICT) {
            throw new Exception('Could not find a connection on ' . $name . '!');
        } else {
            return false;
        }
    }

    /**
     * Gets the configurations for a connected entry by its column name
     * @param $column
     * @return bool|mixed
     */
    public function getConnectedEntityByColumnName($column)
    {
        if (isset($this->ConnectedEntities[$column])) {
            return array_merge(['column' => $column], $this->ConnectedEntities[$column]);
        } else {
            return false;
        }
    }

    /**
     * Gets the configurations for a connected entry by its alias
     * @param $alias
     * @return bool|mixed
     * @throws Exception
     */
    public function getConnectedEntityByAlias($alias)
    {
        if (($column = $this->getColumnNameByAlias($alias))) {
            return $this->getConnectedEntityByColumnName($column);
        } else {
            return false;
        }
    }

    /**
     * Gets a value
     * @param $name
     * @return mixed|null
     * @throws Exception
     */
    public function __get($name)
    {
        if (isset($this->Changelist[$name])) {
            // look inside the changelist
            return $this->Changelist[$name];
        } else if (isset($this->Columns[$name]) && isset($this->Changelist[$this->Columns[$name]])) {
            // changelist lookup (via column name)
            return $this->Changelist[$this->Columns[$name]];

        } else if (($entity = $this->getConnectedEntity($name))) {
            // look for a connected entry
            // returns a Entity or List object
            return $entity;

        } else if ($this->isLoaded()) {
            // look inside the loaded entry

            if (isset($this->Columns[$name])) {
                // ... by its column name
                return $this->Entry->$name;
            } else if (($columnName = $this->getColumnNameByAlias($name)) && isset($this->Columns[$columnName])) {
                // ... by its alias
                return $this->Entry->$columnName;
            }
        }

        // default
        return null;
    }


    /*** SETTERS ***/

    /**
     * Adds a value to the changelist
     * @param $name
     * @param $value
     * @throws Exception
     */
    public function __set($name, $value)
    {
        if (isset($this->Columns[$name])) {
            // ... by its column name
            $this->Changelist[$name] = $value;
        } else if (($columnName = $this->getColumnNameByAlias($name)) && isset($this->Columns[$columnName])) {
            // ... by its alias
            $this->Changelist[$columnName] = $value;
        }
    }

}