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
 * Pdo wrap class
 *
 * @author   Pietro Celeste <p.celeste@osynapsy.net>
 */
class DboPdo extends \PDO implements InterfaceDbo
{
    private $cursor = null;

    private $connectionStringDecoder = [
        'sqlite' => ['type','dbname'],
        '*' => ['type','host','dbname','username','password','port']
    ];

    private $param = [
        'queryParameterDummy' => '?',
        'backticks' => '"'
    ];

    public function __construct($osyConnectionString)
    {
        $option = [];
        $pdoConnectionString = $this->buildPDOConnectionString($osyConnectionString);
        switch ($this->type) {
            case 'sqlite':
                parent::__construct("{$this->type}:{$this->dbname}");
                break;
            case 'mysql' :
                $option[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                $this->backticks = '`';
            default:
                parent::__construct($pdoConnectionString, $this->username, $this->password, $option);
                break;
        }
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    private function buildPDOConnectionString($osyConnectionString)
    {
        $cnParameters = explode(':', $osyConnectionString);
        if (empty($cnParameters)) {
            throw new \Exception('connection parameters is empty');
        }
        $decoder = array_key_exists($cnParameters[0], $this->connectionStringDecoder) ?
                   $this->connectionStringDecoder[$cnParameters[0]] :
                   $this->connectionStringDecoder['*'];
        foreach($decoder as $propertyIdx => $property) {
            if (!empty($cnParameters[$propertyIdx])) {
                $this->{$property} = $cnParameters[$propertyIdx];
            }
        }
        $pdoConnectionString = "{$this->type}:host={$this->host};dbname={$this->dbname}";
        if ($this->port) {
            $pdoConnectionString .= ";port={$this->port}";
        }
        return $pdoConnectionString;
    }

    public function begin()
    {
        $this->beginTransaction();
    }

    public function countColumn()
    {
       return $this->cursor->columnCount();
    }

    public function createTemporaryTable($table, array $columns, array $dataset = [])
    {
        $fields = implode(',', array_map(function($value){return "$value VARCHAR(255)";}, $columns));
        $this->execCommand(sprintf("CREATE TEMPORARY TABLE %s (%s)", $table, $fields));
        if (!empty($dataset)) {
            $this->multiInsert($table, array_values($dataset));
        }
    }

    public function getType()
    {
        return $this->param['type'];
    }

    //Metodo che setta il parametri della connessione
    public function setParam($p, $v)
    {
        $this->param[$p] = $v;
    }

    //Prendo l'ultimo valore di un campo autoincrement dopo l'inserimento
    public function lastId()
    {
        return $this->lastInsertId();
    }

    public function execCommand($command, $parameters = null)
    {
        if (empty($parameters)) {
            return parent::exec($command);
        }
        $s = $this->prepare($command);
        return $s->execute($parameters);
    }

    public function execMulti($cmd, $rows)
    {
        $this->beginTransaction();
        $s = $this->prepare($cmd);
        foreach ($rows as $rec) {
            try {
                $s->execute($rec);
            } catch (Exception $e){
                $this->rollBack();
                return sprintf('%s %s %s', $cmd, $e->getMessage(), print_r($rec, true));
            }
        }
        $this->commit();
        return;
    }

    public function find($sql, array $parameters = [])
    {
        return $this->execQuery($sql, $parameters, \PDO::FETCH_NUM);
    }

    public function findAssoc($sql, array $parameters = [])
    {
        return $this->execQuery($sql, $parameters, \PDO::FETCH_ASSOC);
    }

    public function findOne($sql, array $parameters = [])
    {
        return $this->execUnique($sql, $parameters, \PDO::FETCH_NUM);
    }

    public function findOneAssoc($sql, array $parameters = [])
    {
        return $this->execUnique($sql, $parameters, \PDO::FETCH_ASSOC);
    }

    public function findColumn($sql, array $parameters = [], $columnIdx = 0)
    {
        return $this->execQuery($sql, $parameters, \PDO::FETCH_COLUMN, $columnIdx);
    }

    public function findKeyPair($sql, array $parameters = [])
    {
        return $this->execQuery($sql, $parameters, \PDO::FETCH_KEY_PAIR);
    }

    protected function execQuery($sql, $parameters = null, $pdoFetchMethod = null, $fetchColumnIdx = null)
    {
        $this->cursor = $this->prepare($sql);
        $this->cursor->execute($parameters);
        if (!is_null($fetchColumnIdx)) {
            return $this->cursor->fetchAll(\PDO::FETCH_COLUMN, $fetchColumnIdx);
        }
        return $this->cursor->fetchAll($pdoFetchMethod);
    }

    protected function execUnique($sql, $parameters = null, $fetchMethod = self::FETCH_NUM)
    {
        $raw = $this->execQuery($sql, $parameters, $fetchMethod);
        if (empty($raw)) {
            return null;
        }
        $one = array_shift($raw);
        return is_array($one) && count($one) == 1 ? array_values($one)[0] : $one;
    }

    public function getColumns($stmt = null)
    {
        if (is_null($stmt)) {
            $stmt = $this->cursor;
        }
        $cols = array();
        $ncol = $stmt->columnCount();
        for ($i = 0; $i < $ncol; $i++) {
            $cols[] = $stmt->getColumnMeta($i);
        }
        return $cols;
    }

    public function insert($table, array $parameters)
    {
        $fields = $placeholders = $values = [];
        foreach ($parameters as $field => $value) {
            $fields[] = $field;
            $placeholders[] = '?';
            $values[] = $value;
        }
        $sqlInsert = 'insert into '.$table.'('.implode(',',$fields).')';
        $sqlInsert .= ' values ('.implode(',',$placeholders).')';
        $this->execCommand($sqlInsert, $values);
        return $this->lastId();
    }

    public function multiInsert($table, array $rawValues, array $OnUpdateKey = [])
    {
        if (empty($rawValues[0]) || !is_array($rawValues[0])) {
            return;
        }
        $fields = $placeholder = array();
        foreach (array_keys($rawValues[0]) as $field) {
            $fields [] = $field;
            $placeholder[] = '?';
        }
        $arguments = '('.implode(',',$placeholder).')';
        $params = [];
        $values = [];
        foreach ($rawValues as $record) {
            $params[] = $arguments;
            $values = array_merge($values, array_values($record));
        }
        $command = 'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES '.implode(',', $params);
        if (!empty($OnUpdateKey)) {
            array_walk($OnUpdateKey, function(&$item, $key) { $item = $key .' = '.$item; });
            $command .= ' ON DUPLICATE KEY UPDATE '.implode(' , ', $OnUpdateKey);
        }
        $this->execMulti($command, [$values]);
        return $this->lastId();
    }

    public function update($table, array $arg, array $filters)
    {
        $fields = $values = $where = [];
        foreach ($arg as $field => $value) {
            $fields[] = "{$field} = ?";
            $values[] = $value;
        }
        foreach ($filters as $field => $value) {
            if (!is_array($value)) {
                $where[] = $field . " = ?";
                $values[] = $value;
                continue;
            }
            $where[] = $field . ' IN (' .implode(',',array_fill(0, count($value), '?')) . ')';
            $values = array_merge($values, array_values($value));
        }
        $command = 'UPDATE '.$table.' SET '.implode(', ', $fields).' WHERE '.implode(' AND ', $where);
        return $this->execCommand($command, $values);
    }

    public function delete($table, array $filters)
    {
        $values = $where = [];
        foreach ($filters as $field => $value) {
            if (!is_array($value)) {
                $where[] = $field . " = ?";
                $values[] = $value;
                continue;
            }
            $where[] = $field . ' IN (' .implode(',',array_fill(0, count($value), '?')) . ')';
            $values = array_merge($values, array_values($value));
        }
        $command = 'DELETE FROM '.$table.' WHERE '.implode(' AND ',$where);
        $this->execCommand($command, $values);
    }

    public function replace($table, $args, $conditions)
    {
        if ($this->selectOne($table, $conditions, ['count(*)'], self::FETCH_NUM)) {
            $this->update($table, $args, $conditions);
            return;
        }
        $this->insert($table, array_merge($args, $conditions));
    }

    public function replaceRet($table, $args, $conditions, $fieldToReturn = null)
    {
        $result = $this->selectOne($table, $conditions, [$fieldToReturn ?? 'count(*)'], self::FETCH_NUM);
        if (!empty($result)) {
            $this->update($table, $args, $conditions);
            return $result;
        }
        return $this->insert($table, array_merge($args, $conditions));
    }

    public function select($table, array $conditions, array $fields = ['*'], array $orderBy = [], int $fetchMethod = \PDO::FETCH_ASSOC)
    {
        list($sql, $params) = $this->selectBuild($table, $fields, $conditions, $orderBy);
        return $this->execQuery($sql, $params, $fetchMethod);
    }

    public function selectOne($table, array $conditions, array $fields = ['*'], int $fetchMethod = \PDO::FETCH_ASSOC)
    {
        list($sql, $params) = $this->selectBuild($table, $fields, $conditions, []);
        return $this->execUnique($sql, $params, $fetchMethod);
    }

    private function selectBuild($table, array $fields, array $conditions, array $orderBy)
    {
        $sql = ['SELECT '. implode(',', $fields), ' FROM ' . $table];
        if (empty($conditions)) {
            return $sql;
        }
        $where = $params = [];
        foreach ($conditions as $field => $value) {
            if (is_null($value)) {
                $where[] = $field.' is null';
                continue;
            } elseif (is_numeric($field)) {
                $where[] = $value;
                continue;
            }
            $where[] = $field.' = :'.sha1($field);
            $params[sha1($field)] = $value;
        }
        $sql[] = 'WHERE '.implode(' AND ', $where);
        if (!empty($orderBy)) {
            $sql[] = 'ORDER BY '.implode(' ', $orderBy);
        }
        return [implode(PHP_EOL, $sql), $params];
    }

    public function selectFactory(array $fields = []) : Select
    {
        $Select = new Select($fields);
        $Select->setDb($this);
        return $Select;
    }

    public function cast($field,$type)
    {
        $cast = $field;
        switch ($this->getType()) {
            case 'pgsql':
                $cast .= '::'.$type;
                break;
        }
        return $cast;
    }

    public function __get($key)
    {
        return array_key_exists($key, $this->param) ? $this->param[$key] : null;
    }

    public function __set($key, $value)
    {
        return $this->param[$key] = $value;
    }
}
