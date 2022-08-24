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

/**
 * Description of Pagination
 *
 * @author Pietro Celeste
 */
class Paginator
{
    const META_PAGE_MIN = 'pageMin';
    const META_PAGE_MAX = 'pageLast';
    const META_PAGE_CUR = 'pageCurrent';
    const META_PAGE_TOT = 'pageTotal';
    const META_PAGE_SIZE = 'pageSize';

    protected $childs = [];
    protected $data = [];
    protected $errors = [];
    private $columns = [];
    private $db;

    private $id;

    private $filters = [];
    private $fields = [];
    private $par;
    private $sort = '1 DESC';
    private $sortDefault;
    private $sql;
    private $meta = [
        'pageSize' => 10,
        'pageTotal' => 1,
        'pageMin' => 0,
        'pageLast' => 0,
        'pageCurrent' => 1,
        'rowsTotal' => 0,
        'rowsFrom' => 0,
        'rowsTo' => 0
    ];

    private $reserved = [
        'page',
        'size',
        'sort'
    ];

    /**
     * Costructor of pager component.
     *
     * @param type $id Identify of component
     * @param type $request Osynapsy Request object
     * @param type $defaultPageSize page size
     */
    public function __construct($id, $request, $defaultPageSize = 20)
    {
        $this->id = $id;
        $this->request = $request;
        $this->setPageSize($defaultPageSize);
        $this->setFilters($this->getRequest('get'));
        if (!empty($this->getRequest('get.sort'))) {
            $this->setSort($this->getRequest('get.sort'));
        }
    }

    public function addField($field)
    {
        $this->fields[] = $field;
    }

    public function addFilter($field, $value = null)
    {
        $this->filters[$field] = $value;
    }

    public function get($currentPage = null)
    {
        if (empty($this->data)) {
            $this->loadData($currentPage ?? $this->getRequest('get.page'), true);
        }
        $pageCurrent = $this->getMeta(self::META_PAGE_CUR);
        $pageTotal = $this->getMeta(self::META_PAGE_TOT);
        $pagerDimension = min(7, $pageTotal);
        $pagerMedian = floor($pagerDimension / 2);
        $pagerMinimum = max(1, $pageCurrent - $pagerMedian);
        $pagerMaximum = max($pagerDimension, min($pageCurrent + $pagerMedian, $pageTotal));
        $this->setMeta(self::META_PAGE_MAX, $pagerMaximum);
        $this->setMeta(self::META_PAGE_MIN, min($pagerMinimum, $pageTotal - $pagerDimension + 1));
        return $this->data;
    }

    protected function loadData($requestPage = null, $exceptionOnError = false)
    {
        if (empty($this->sql)) {
            return [];
        }
        $where = empty($this->filters) ? '' : $this->buildFilter();
        $count = sprintf("SELECT COUNT(*) FROM (%s) a %s",$this->sql, $where);
        $this->meta['rowsTotal'] = $this->getDb()->findOne($count, $this->par);
        $this->calcPage($requestPage);
        switch ($this->getDb()->getType()) {
            case 'oracle':
                $sql = $this->buildOracleQuery($where);
                break;
            case 'pgsql':
                $sql = $this->buildPgSqlQuery($where);
                break;
            default:
                $sql = $this->buildMySqlQuery($where);
                break;
        }
        $this->data = $this->getDb()->findAssoc($sql, $this->par);
        $this->columns = $this->getDb()->getColumns();
        return empty($this->data) ? [] : $this->data;
    }

    protected function buildSqlQuery($rawQuery, $where)
    {
        $orderBy = empty($this->sort) ? '' : sprintf(PHP_EOL.'ORDER BY %s', $this->sort);
        return sprintf('SELECT a.* FROM (%s) a %s %s', $rawQuery, $where, $orderBy);
    }

    private function buildMySqlQuery($where)
    {
        $sql = $this->buildSqlQuery($this->sql, $where);
        if (!empty($this->meta[self::META_PAGE_SIZE])) {
            $startFrom = max(0, ($this->meta['pageCurrent'] - 1) * $this->meta[self::META_PAGE_SIZE]);
            $sql .= sprintf(PHP_EOL."LIMIT %s, %s", $startFrom,$this->meta[self::META_PAGE_SIZE]);
        }
        $this->setMeta('rowsFrom', $startFrom);
        $this->setMeta('rowsTo', min($this->getMeta('rowsTotal'), $startFrom + $this->meta[self::META_PAGE_SIZE]));
        return $sql;
    }

    private function buildPgSqlQuery($where)
    {
        $sql = $this->buildSqlQuery($this->sql, $where);
        if (!empty($this->meta['pageSize'])) {
            $startFrom = max(0, ($this->meta['pageCurrent'] - 1) * $this->meta['pageSize']);
            $sql .= sprintf(PHP_EOL."LIMIT %s OFFSET %s", $this->meta['pageSize'], $startFrom);
        }
        return $sql;
    }

    private function buildOracleQuery($where)
    {
        $sql = sprintf("SELECT a.* FROM (SELECT b.*,rownum as \"_rnum\" FROM (%s) b) a ", $this->buildQuery($this->sql, $where));
        if (!empty($this->meta['pageSize'])) {
            $startFrom = (($this->meta['pageCurrent'] - 1) * $this->meta['pageSize']) + 1 ;
            $endTo = ($this->meta['pageCurrent'] * $this->meta['pageSize']);
            $sql .=  sprintf(PHP_EOL."WHERE \"_rnum\" BETWEEN %s AND %s", $startFrom, $endTo);
        }
        return $sql;
    }

    private function buildFilter()
    {
        $filter = [];
        $i = 0;
        foreach ($this->filters as $field => $value) {
            if (is_null($value)) {
                continue;
            }
            $filter[] = sprintf("$field = %s", $this->getDb()->getType() == 'oracle' ? ':'.$i : '?');
            $this->par[] = $value;
            $i++;
        }
        return sprintf(" WHERE %s", implode(' AND ',$filter));
    }

    private function calcPage($requestPage)
    {
        $this->meta['pageCurrent'] = max(1,(int) $requestPage);
        if ($this->meta['rowsTotal'] == 0 || empty($this->meta['pageSize'])) {
            return;
        }
        $this->setMeta('pageTotal', ceil($this->meta['rowsTotal'] / $this->meta['pageSize']));
        switch ($requestPage) {
            case 'first':
                $this->meta['pageCurrent'] = 1;
                break;
            case 'last' :
                $this->meta['pageCurrent'] = $this->meta['pageTotal'];
                break;
            case 'prev':
                if ($this->meta['pageCurrent'] > 1){
                    $this->meta['pageCurrent']--;
                }
                break;
            case 'next':
                if ($this->meta['pageCurrent'] < $this->meta['pageTotal']) {
                    $this->meta['pageCurrent']++;
                }
                break;
            default:
                $this->meta['pageCurrent'] = min($this->meta['pageCurrent'], $this->meta['pageTotal']);
                break;
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDb()
    {
        return $this->db;
    }

    public function getErrors()
    {
        return implode(PHP_EOL, $this->errors);
    }

    public function getRequest($key)
    {
        return $this->request->get($key);
    }

    public function getSort()
    {
        return $this->sort;
    }

    public function getMeta($key = null)
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : null;
    }

    public function getTotal($key)
    {
        return $this->getStatistic('total'.ucfirst($key));
    }

    private function loadChilds()
    {
        if (empty($this->childs)) {
            return;
        }
        foreach ($this->childs as $fieldName => $childSql) {
            $this->loadChild($childSql['sql'], $childSql['par'], $childSql['foreignKeys'], $fieldName);
        }
    }

    private function loadChild($sql, $parameters, $foreignKeys, $fieldName)
    {
        $rs = $this->getDb()->findAssoc($sql, $parameters);
        foreach ($this->data as $key => $parentRecord) {
            foreach($rs as $childRecord) {
                if (empty($parentRecord[$fieldName])) {
                    $parentRecord[$fieldName] = [];
                }
                $result = $this->matchChild($parentRecord, $childRecord, $foreignKeys, $fieldName);
                if (empty($result)) {
                    continue;
                }
                $parentRecord[$fieldName][] = $result;
            }
            $this->data[$key] = $parentRecord;
        }
    }

    private function matchChild($parentRecord, $childRecord, $foreignKeys)
    {
        foreach($foreignKeys as $childKey => $parentKey) {
            if ($parentRecord[$parentKey] !== $childRecord[$childKey]) {
                return;
            }
            return $childRecord;
        }
    }

    public function setSort($fields)
    {
        $this->sort = str_replace(['_asc','_desc'], [' ASC', ' DESC'], empty($fields) ? $this->sortDefault : $fields);
        return $this;
    }

    public function setSortDefault($fields)
    {
        $this->sortDefault = $fields;
    }

    public function setPageSize($defaultSize)
    {
        $getSize = $this->getRequest('get.size');
        $size = empty($getSize) ? $defaultSize : $getSize;
        $this->setMeta('pageSize', min(1000, $size));
        $this->setMeta('pageDimension', min(1000, $size));
    }

    public function setSql($db, $cmd, array $par = [])
    {
        $this->db = $db;
        $this->sql = $cmd;
        $this->par = $par;
        return $this;
    }

    public function setSqlChilds($cmd, array $par = [], $foreignKeys = [], $fieldName = 'childs')
    {
        $this->childs[$fieldName] = [
            'sql' => $cmd,
            'par' => $par,
            'foreignKeys' => $foreignKeys
        ];
        return $this;
    }

    public function setFilters(array $filters)
    {
        foreach($filters as $field => $value) {
            if (in_array($field, $this->reserved)) {
                continue;
            }
            $this->addFilter($field, $value);
        }
    }

    private function setMeta($key, $value)
    {
        $this->meta[$key] = $value;
    }

    public function getJson()
    {
        return json_encode($this->get());
    }
}
