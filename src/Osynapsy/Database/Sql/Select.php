<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Db\Sql;

use Osynapsy\Db\Driver\InterfaceDbo;

/**
 * Description of SqlQuery
 *
 * @author Peter
 */
class Select
{
    private $id;
    private $debug;
    private $dummy;
    private $parent;
    private $part = [
        'WITH' => [
            'separator' => ' '.PHP_EOL
        ],
        'SELECT' => [
            'separator' => ','.PHP_EOL
        ],
        'JOIN' => [
            'separator' => ' '.PHP_EOL,
            'postfix' => PHP_EOL
        ],
        'WHERE' => [
            'separator' => ' '
        ],
        'GROUP BY' => [
            'separator' => ','.PHP_EOL
        ],
        'ORDER BY' => [
            'separator' => ','.PHP_EOL
        ]
    ];
    private $elements = [
        'WITH' => [],
        'SELECT' => [],
        'FROM' => [],
        'JOIN' => [],
        'WHERE' => [],
        'GROUP BY' => [],
        'ORDER BY' => [],
        'LIMIT' => null
    ];
    private $parameters = [];

    public function __construct($fields = [], $parent = null, $debug = false, $id = 'main')
    {
        $this->debug = $debug;
        $this->parent = empty($parent) ? $this : $parent;
        $this->id = $id;
        if (!empty($fields)) {
            $this->select($fields);
        }
    }

    public function parameters(array $parameters = [])
    {
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }

    private function isAssoc($array)
    {
        return !($array === array_values($array));
    }

    public function condition($condition, callable $function)
    {
        if (!$condition) {
            return $this;
        }
        $function($this);
        return $this;
    }

    public function with($queryAlias, $subQuery)
    {
        $this->elements['WITH'][] = sprintf('%s AS ( %s )',$queryAlias, $subQuery);
    }

    public function withRecursive($queryAlias, $queryRecursive, $parameters = [])
    {
        $stringParameters = empty($parameters) ? '' : '('.implode(',',$parameters).')';
        $this->with(sprintf('RECURSIVE %s %s',$queryAlias, $stringParameters), $queryRecursive);
    }

    public function select(array $fields = null, array $parameters = [])
    {
        if (empty($fields)) {
            return;
        }
        if (!is_array($fields)) {
            $fields = array($fields);
        } elseif ($this->isAssoc($fields)) {
            $app = array();
            foreach($fields as $key => $value) {
                $app[] = $value.' as "'.$key.'"';
            }
            $fields = $app;
        }
        $this->elements['SELECT'] = array_merge($this->elements['SELECT'], $fields);
        $this->parameters($parameters);
        return $this;
    }

    public function from($table, $fields = null)
    {
        $this->select($fields);
        $this->elements['FROM'] = $table;
        return $this;
    }

    public function join($table)
    {
        $this->elements['JOIN'][] = 'INNER JOIN '.$table;
        return $this;
    }

    public function joinLeft($table)
    {
        $this->elements['JOIN'][] = 'LEFT JOIN '.$table;
        return $this;
    }

    public function on(array $conditions, array $parameters = [])
    {
        $this->elements['JOIN'][] = 'ON ('.implode(' AND ', $conditions).')';
        if (!empty($parameters)) {
            $this->parameters($parameters);
        }
        return $this;
    }

    public function and_()
    {
    }

    public function where($rawCondition, array $parameters = [], $operator = 'AND')
    {
        $condition = is_array($rawCondition) ? '('.implode(' OR ', $rawCondition).')'
                                             : $rawCondition;
        if (!empty($this->elements['WHERE'])) {
            $condition = $operator . ' ' . $condition;
        }
        $this->elements['WHERE'][] = $condition;
        $this->parameters($parameters);
        return $this;
    }

    public function groupBy(array $fields)
    {
        if (empty($fields)) {
            return;
        }
        $this->elements['GROUP BY'] = array_merge($this->elements['GROUP BY'], $fields);
        return $this;
    }

    public function orderBy(array $fields)
    {
        if (empty($fields)) {
            return;
        }
        $this->elements['ORDER BY'] = array_merge($this->elements['ORDER BY'], $fields);
        return $this;
    }

    public function limit($from, $width)
    {
        $this->elements['LIMIT'] = "$from,$width";
    }

    public function __toString()
    {
        $string = '';
        foreach ($this->elements as $word => $items) {
            if (empty($items)) {
                continue;
            }
            $string .= $word == 'JOIN' ? '' : $word.' ';
            $string .= $this->prefix($word);
            $string .= is_array($items) ? implode($this->getSeparator($word), $items) : ' '.$items;
            $string .= $this->postfix($word);
            $string .= PHP_EOL;
        }
        return $string;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    private function getSeparator($word)
    {
        return array_key_exists($word, $this->part) ? $this->part[$word]['separator'] : ' ';
    }

    private function prefix($word)
    {
        //return array_key_exists($word, $this->part) ? $this->part[$word][0] : ' ';
        return isset($this->part[$word]) && isset($this->part[$word]['prefix']) ? $this->part[$word]['prefix'] : ' ';
    }

    private function postfix($word)
    {
        return isset($this->part[$word]) && isset($this->part[$word]['postfix']) ? $this->part[$word]['postfix'] : ' ';
    }

    public function __if__($condition)
    {
        return $condition ? $this->getMaster() : $this->getDummy();
    }

    public function __elseif__($condition)
    {
        return $condition ? $this->getMaster() : $this->getDummy();
    }

    public function __else__()
    {
        //If parent is not set then prev if condition is verificated
        //else don't find
        //If parent is set if condition is false then else case is verificated
        return $this->getId() === 'main' ? $this->getDummy() : $this->getMaster();
    }

    public function __endif__()
    {
        return $this->getMaster();
    }

    private function getDummy()
    {
        if (empty($this->dummy)) {
            $this->dummy = new Select(null, $this->parent, false, 'dummy');
        }
        return $this->dummy;
    }

    public function getId()
    {
        return $this->id;
    }

    private function getMaster()
    {
        return $this->parent;
    }

    public function setDb(InterfaceDbo $db)
    {
        $this->db = $db;
    }

    public function exec()
    {
        return $this->db->findAssoc($this->__toString(), $this->getParameters());
    }
}
