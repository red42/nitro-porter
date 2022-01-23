<?php

namespace Porter\Database;

/**
 * Creating desired db instances on the go
 * Class DbFactory
 */
class DbFactory
{
    /**
     * @var array DB connection info
     */
    private $dbInfo;

    /**
     * @var string php database extension
     */
    private $extension;

    /**
     * DbFactory constructor.
     *
     * @param array  $args      db connection parameters
     * @param string $extension db extension
     */
    public function __construct(array $args, $extension)
    {
        $this->dbInfo = $args;
        $this->extension = $extension;
    }

    /**
     * Returns a db instance
     *
     * @return object db instance
     */
    public function getInstance()
    {
        $className = '\Porter\Database\\' . $this->extension . 'Db';
        if (class_exists($className)) {
            $dbFactory = new $className($this->dbInfo);
            if ($dbFactory instanceof DbResource) {
                return $dbFactory;
            } else {
                trigger_error($className . 'does not implement DbResource.');
            }
        } else {
            trigger_error($this->extension . ' extension not found.');
        }
    }
}
