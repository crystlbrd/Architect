<?php


namespace crystlbrd\Architect;


use crystlbrd\Architect\Blueprints\Entity;
use Exception;

class Connector
{
    const VALID_TYPES = [
        '1:1',
        '1:n',
        'n:1',
        'n:m'
    ];

    protected $Origin;
    protected $Target;
    protected $Type;

    public function __construct(Entity $origin, Entity $target, string $type)
    {
        // init
        $this->Origin = $origin;
        $this->Target = $target;
        $this->Type = $type;

        // Validate the connection type
        $this->validateType($type, true);


    }

    /**
     * Checks if a given type is valid
     * @param string $type
     * @param bool $throwException
     * @return bool
     * @throws Exception
     */
    protected function validateType(string $type, bool $throwException = false): bool
    {
        if (!in_array($type, self::VALID_TYPES)) {
            if ($throwException) {
                throw new Exception('Invalid connection type!');
            } else {
                return true;
            }
        } else {
            return true;
        }
    }

    public function getEntities()
    {
    }
}