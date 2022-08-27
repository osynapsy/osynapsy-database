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
 * Oci wrap class
 *
 *
 * @author   Pietro Celeste <p.celeste@osynapsy.net>
 */
class DboOci implements InterfaceDbo
{
    const FETCH_NUM = 'NUM';
    const FETCH_ASSOC = 'ASSOC';

    private $__par = array();
    private $cursor = null;
    public  $backticks = '"';
    public  $cn = null;
    private $__transaction = false;
    //private $rs;

    public function __construct($str)
    {
        $par = explode(':',$str);
        $this->__par['typ'] = trim($par[0]);
        $this->__par['hst'] = trim($par[1]);
        $this->__par['db']  = trim($par[2]);
        $this->__par['usr'] = trim($par[3]);
        $this->__par['pwd'] = trim($par[4]);
        $this->__par['query-parameter-dummy'] = 'pos';
        $this->cn = oci_connect(
            $this->__par['usr'],
            $this->__par['pwd'],
            "{$this->__par['hst']}/{$this->__par['db']}",
            'AL32UTF8'
        );
        if (!$this->cn) {
            $e = oci_error();
            trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
        } else {
            $this->execCommand("ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD'");
        }
    }

    public function begin()
    {
        $this->beginTransaction();
    }

    public function beginTransaction()
    {
        $this->__transaction = true;
    }

    public function columnCount()
    {
       return $this->cursor->columnCount();
    }

    public function commit()
    {
        oci_commit($this->cn );
    }

    public function rollback()
    {
        oci_rollback($this->cn );
    }
    public function quote($value)
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    function getType()
    {
       return 'oracle';
    }

    //Metodo che setta il parametri della connessione
    function setParam($p,$v)
    {
      $this->__par[$p] = $v;
    }

    //Prendo l'ultimo valore di un campo autoincrement dopo l'inserimento
    public function lastInsertId($arg)
    {
        foreach ($arg as $k => $v) {
            if (strpos('KEY_',$k) !== false) {
                return $v;
            }
        }
    }

    public function execMulti($cmd, $par)
    {
        $this->beginTransaction();
        $s = $this->prepare($cmd);
        foreach ($par as $rec) {
            try {
                $s->execute($rec);
            } catch (Exception $e){
                echo $e;
                var_dump($rec);
                return;
            }
        }
        $this->commit();
    }

    public function execCommand($cmd, $par = null, $rs_return = true)
    {
        $rs = oci_parse($this->cn, $cmd);
        if (!$rs) {
            $e = oci_error($this->cn);  // For oci_parse errors pass the connection handle
            throw new \Exception($e['message']);
        }
        if (!empty($par) && is_array($par)) {
            foreach ($par as $k => $v) {
                $$k = $v;
                // oci_bind_by_name($rs, $k, $v) does not work
                // because it binds each placeholder to the same location: $v
                // instead use the actual location of the data: $$k
                $l = strlen($v) > 255 ? strlen($v) : 255;
                oci_bind_by_name($rs, ':'.$k, $$k, $l);
            }
        }

        $ok = $this->__transaction ? @oci_execute($rs, OCI_NO_AUTO_COMMIT) : @oci_execute($rs);

        if (!$ok) {
            $e = oci_error($rs);  // For oci_parse errors pass the connection handle
            throw new \Exception($e['message'].PHP_EOL.$e['sqltext'].PHP_EOL.print_r($par,true));
        }

        if ($rs_return) {
            return $rs;
        }

        foreach ($par as $k=>$v) {
            $par[$k] = $$k;
        }
        oci_free_statement($rs);
        return $par;
    }

    protected function execQuery($sql, $parameters, $fetchMethod)
    {
        $this->cursor = $this->execCommand($sql, $parameters);
        oci_fetch_all($this->cursor, $result, null, null, OCI_FETCHSTATEMENT_BY_ROW | OCI_RETURN_NULLS | OCI_RETURN_LOBS | $fetchMethod);
        return $result;
    }

    public function getIterator($rs, $par = null, $method = null)
    {
	    if (is_string($rs)) {
	        $rs = $this->execCommand($rs, $par);
	    } else {
	        $method = $par;
	    }
	    switch($method) {
	        case 'BOTH':
	            $method = OCI_BOTH;
	            break;
	        case 'NUM':
	            $method = OCI_NUM;
	            break;
	        default:
	            $method = OCI_ASSOC;
	            break;
	    }
	    while ($record = oci_fetch_array($rs, $method | OCI_RETURN_NULLS)) {
	        yield $record;
	    }
	    $this->freeRs($rs);
    }
    public function query($sql)
    {
        return $this->execCommand($sql);
    }

    public function fetchAll2($rs)
    {
        oci_fetch_all(
            $rs,
            $res,
            null,
            null,
            OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC | OCI_RETURN_NULLS | OCI_RETURN_LOBS
        );
        return $res;
    }

    public function fetchAll($rs)
    {
        $result = array();
        while ($record = oci_fetch_array($rs, OCI_ASSOC|OCI_RETURN_NULLS)) {
            $result[] = $record;
        }
        return $result;
    }

    protected function execUnique($sql, $parameters = null, int $fetchMethod = OCI_NUM)
    {
       $res = $this->execQuery($sql, $parameters, $fetchMethod);
       if (empty($res)) {
           return null;
       }
       return count($res[0])==1 ? $res[0][0] : $res[0];
    }

    public function getColumns($stmt = null)
    {
        if (is_null($stmt)) {
            $stmt =  $this->cursor;
        }
        $cols = array();
        $ncol = oci_num_fields($stmt);
        for ($i = 1; $i <= $ncol; $i++) {
            $cols[] = array(
                'native_type' => oci_field_type($stmt,$i),
                'flags' => array(),
                'name' => oci_field_name($stmt,$i),
                'len' => oci_field_size($stmt,$i),
                'pdo_type' => oci_field_type_raw($stmt,$i)
            );
        }
        return $cols;
    }

    public function exec($sql, array $parameters = [])
    {
        return $this->execQuery($sql, $parameters, OCI_NUM);
    }

    public function findAssoc($sql, array $parameters = [])
    {
        return $this->execQuery($sql, $parameters, OCI_ASSOC);
    }

    public function findOne($sql, array $parameters = [])
    {
        return $this->execUnique($sql, $parameters, OCI_NUM);
    }

    public function findOneAssoc($sql, array $parameters = [])
    {
        return $this->execUnique($sql, $parameters, OCI_ASSOC);
    }

    public function findColumn($sql, array $parameters = [], $columnIdx = 0)
    {
    }

    public function findKeyPair($sql, array $parameters = [])
    {
    }

    public function insert($table, array $values, $keys = array())
    {
        $command  = 'INSERT INTO '.$table;
        $command .= '('.implode(',', array_keys($values)).')';
        $command .= ' VALUES ';
        $command .= '(:'.implode(',:',array_keys($values)).')';
        if (is_array($keys) && !empty($keys)) {
            $command .= ' RETURNING ';
            $command .= implode(',',array_keys($keys));
            $command .= ' INTO ';
            $command .= ':KEY_'.implode(',:KEY_',array_keys($keys));
            foreach ($keys as $k => $v) {
                $values['KEY_'.$k] = null;
            }
        }
        $values = $this->execCommand($command, $values, false);
        $res = array();
        foreach ($values as $k => $v) {
            if (strpos($k,'KEY_') !== false) {
                $res[str_replace('KEY_','',$k)] = $v;
            }
        }
        return $res;
    }

    public function update($table, array $values, array $condition)
    {
        $fields = $where = [];
        foreach ($values as $field => $value) {
            $fields[] = "{$field} = :{$field}";
        }
        foreach ($condition as $field => $value) {
            if (is_null($value)) {
                $where[] = "$field is null";
                continue;
            }
            $where[] = "$field = :WHERE_{$field}";
            $values['WHERE_'.$field] = $value;
        }
        $cmd = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $fields), implode(' AND ',$where));
        return $this->execCommand($cmd, $values);
    }

    public function delete($table, array $keys)
    {
        $where = array();
        if (!is_array($keys)){
            $keys = array('id'=>$cnd);
        }
        foreach($keys as $k=>$v){
            $where[] = "{$k} = :{$k}";
        }
        $cmd  = 'DELETE FROM '.$table;
        $cmd .= ' WHERE '.implode(' AND ',$where);
        $this->execCommand($cmd, $keys);
    }

    public function replace($table, array $args, array $conditions)
    {
        $result = $this->select($table, ['NUMROWS' => 'count(*)'], $conditions);
        if (!empty($result) && !empty($result[0]) && !empty($result[0]['NUMROWS'])) {
            $this->update($table, $args, $conditions);
            return;
        }
        $this->insert($table, array_merge($args, $conditions));
    }

    public function select($table, array $fields, array $condition)
    {
        $where = array();
        foreach ($condition as $field => $value) {
            if (is_null($value)) {
                $where[] = "$field is null";
                continue;
            }
            $where[] = "$field = :WHERE_{$field}";
            $values['WHERE_'.$field] = $value;
        }
        $cmd = 'SELECT '.implode(', ',$fields);
        $cmd .= ' FROM '.$table;
        $cmd .= ' WHERE ';
        $cmd .= implode(' AND ',$where);
        return $this->execQuery($cmd, $values, 'ASSOC');
    }

    public function selectFactory(array $fields = [])
    {
        $Select = new Select($fields);
        $Select->setDb($this);
        return $Select;
    }

    public function par($p)
    {
        return array_key_exists($p,$this->__par) ? $this->__par[$p] : null;
    }

    public function freeRs($rs)
    {
        oci_free_statement($rs);
    }

    public function close()
    {
        oci_close($this->cn);
    }

    public function dateToSql($date)
    {
        $app = explode('/',$date);
        if (count($app) === 3){
            return "{$app[2]}-{$app[1]}-{$app[0]}";
        }
        return $date;
    }

    public function setDateFormat($format = 'YYYY-MM-DD')
    {
        $this->execCommand("ALTER SESSION SET NLS_DATE_FORMAT = '{$format}'");
    }
/*End class*/
}
