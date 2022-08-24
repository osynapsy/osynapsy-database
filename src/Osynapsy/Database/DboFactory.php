<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Database;

use Osynapsy\Database\Driver\DboOci;
use Osynapsy\Database\Driver\DboPdo;
use Osynapsy\Database\Driver\InterfaceDbo;

/**
 * This class build db connection and store it in connectionPool repo.
 *
 * @author Pietro Celeste <p.celeste@spinit.it>
 */
class DboFactory
{
    private $connectionPool = [];
    private $connectionIndex = [];

    /**
     * get a db connection and return
     *
     * @param idx $key
     *
     * @return object
     */
    public function getConnection($key)
    {
        return array_key_exists($key, $this->connectionPool) ? $this->connectionPool[$key] : false;
    }

    /**
     * Execute a db connection and return it
     *
     * @param string $connectionString contains parameter to access db (ex.: mysql:database:host:username:password:port)
     * @param mixed $idx
     * @return InterfaceDbo object
     */
    public function createConnection($connectionString, $idx = null) : InterfaceDbo
    {
        if (array_key_exists($connectionString, $this->connectionIndex)) {
            return $this->connectionPool[$this->connectionIndex[$connectionString]];
        }
        $databaseConnection = strtok($connectionString, ':') === 'oracle' ? new DboOci($connectionString) : new DboPdo($connectionString);
        $currentIndex = $idx ?? count($this->connectionPool);
        $this->connectionIndex[$connectionString] = $currentIndex;
        $this->connectionPool[$currentIndex] = $databaseConnection;
        return $databaseConnection;
    }
}
