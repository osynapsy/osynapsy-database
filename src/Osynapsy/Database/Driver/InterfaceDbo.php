<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Database\Driver;

use Osynapsy\Database\Sql\Select;

/**
 * Interface for Db class driver
 *
 * @author   Pietro Celeste <p.celeste@osynapsy.net>
 */
interface InterfaceDbo
{
    public function __construct($connectionString);

    public function begin();

    public function commit();

    public function delete($table, array $conditions);

    public function execCommand($command, $parameters);

    public function execMulti($command, $parameterList);

    public function find($query, array $parameters = []);

    public function findAssoc($query, array $parameters = []);

    public function findOne($query, array $parameters = []);

    public function findOneAssoc($query, array $parameters = []);

    public function findColumn($sql, array $parameters = [], $columnIdx = 0);

    public function findKeyPair($sql, array $parameters = []);

    public function getColumns();

    public function getType();

    public function insert($table, array $values);

    public function replace($table, array $values, array $conditions);

    public function rollback();

    public function selectFactory(array $fields) : Select;

    public function update($table, array $values, array $conditions);
}
