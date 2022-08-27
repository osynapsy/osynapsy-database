<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Database;

/**
 * Description of Pagination
 *
 * @author Pietro Celeste <p.celeste@osynapsy.net>
 */
class PaginatorSimple
{
    const META_PAGE_MIN = 'pageMin';
    const META_PAGE_MAX = 'pageLast';
    const META_PAGE_CUR = 'pageCurrent';
    const META_PAGE_TOT = 'pageTotal';
    const META_PAGE_SIZE = 'pageSize';

    protected $data = [];
    private $db;
    private $id;
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

    /**
     * Costructor of pager component.
     *
     * @param type $id Identify of component
     * @param type $request Osynapsy Request object
     * @param type $defaultPageSize page size
     */
    public function __construct($id, $db, $sql, $sqlParameters = [])
    {
        $this->id = $id;
        $this->setSql($db, $sql, $sqlParameters);
    }

    public function addField($field)
    {
        $this->fields[] = $field;
    }

    public function get($currentPage = 1, $pageSize = 20, $sort = null, $filters = [])
    {
        $this->setPageSize($pageSize);
        if (!empty($sort)) {
            $this->setSort($sort);
        }
        if (!empty($filters)) {
            $this->setFilters($filters);
        }
        $data = $this->loadData($this->sql, $this->par, $currentPage, $pageSize);
        $this->setPageSize($pageSize);
        $pageCurrent = $this->getMeta(self::META_PAGE_CUR);
        $pageTotal = $this->getMeta(self::META_PAGE_TOT);
        $pagerDimension = min(7, $pageTotal);
        $pagerMedian = floor($pagerDimension / 2);
        $pagerMinimum = max(1, $pageCurrent - $pagerMedian);
        $pagerMaximum = max($pagerDimension, min($pageCurrent + $pagerMedian, $pageTotal));
        $this->setMeta(self::META_PAGE_MAX, $pagerMaximum);
        $this->setMeta(self::META_PAGE_MIN, min($pagerMinimum, $pageTotal - $pagerDimension + 1));
        return $data;
    }

    protected function loadData($sql, $parameters, $requestPage, $pageSize)
    {
        $count = $this->buildSqlCountFactory($sql);
        $this->meta['rowsTotal'] = $this->getDb()->findOne($count, $parameters);
        $this->calcPage($requestPage);
        $sqlNoPaginated = $this->buildSqlQuery($sql, $this->sort);
        switch ($this->getDb()->getType()) {
            case 'oracle':
                $sqlPaginated = $this->buildOracleQuery($sqlNoPaginated);
                break;
            case 'pgsql':
                $sqlPaginated = $this->buildPgSqlQuery($sqlNoPaginated);
                break;
            default:
                $sqlPaginated = $this->buildMySqlQuery($sqlNoPaginated);
                break;
        }
        $data = $this->getDb()->findAssoc($sqlPaginated, $parameters);
        return is_array($data) ? $data : [];
    }

    protected function buildSqlCountFactory($sql)
    {
        $select = 'SELECTX';
        $startWithSelect = (substr(trim(strtoupper($sql)), 0, strlen($select)) === $select);
        $groupByIsPresent = strpos(strtoupper($sql), 'GROUP BY');
        if ($startWithSelect === false || $groupByIsPresent) {
            return sprintf("SELECT COUNT(*) FROM (%s) a", $sql);
        }
        $fromPosition = strpos(strtoupper($sql), 'FROM');
        return 'SELECT count(*) '.substr($sql, $fromPosition);
    }

    protected function buildSqlQuery($rawQuery, $sort)
    {
        $orderBy = empty($sort) ? '' : sprintf(PHP_EOL.'ORDER BY %s', $sort);
        return sprintf('SELECT a.* FROM (%s) a %s', $rawQuery, $orderBy);
    }

    private function buildMySqlQuery($sql)
    {
        if (!empty($this->meta[self::META_PAGE_SIZE])) {
            $startFrom = max(0, ($this->meta['pageCurrent'] - 1) * $this->meta[self::META_PAGE_SIZE]);
            $sql .= sprintf(PHP_EOL."LIMIT %s, %s", $startFrom,$this->meta[self::META_PAGE_SIZE]);
        }
        $this->setMeta('rowsFrom', $startFrom);
        $this->setMeta('rowsTo', min($this->getMeta('rowsTotal'), $startFrom + $this->meta[self::META_PAGE_SIZE]));
        return $sql;
    }

    private function buildPgSqlQuery($sql)
    {
        if (!empty($this->meta['pageSize'])) {
            $startFrom = max(0, ($this->meta['pageCurrent'] - 1) * $this->meta['pageSize']);
            $sql .= sprintf(PHP_EOL."LIMIT %s OFFSET %s", $this->meta['pageSize'], $startFrom);
        }
        return $sql;
    }

    private function buildOracleQuery($rawSql)
    {
        $sql = sprintf("SELECT a.* FROM (SELECT b.*,rownum as \"_rnum\" FROM (%s) b) a ", $rawSql);
        if (!empty($this->meta['pageSize'])) {
            $startFrom = (($this->meta['pageCurrent'] - 1) * $this->meta['pageSize']) + 1 ;
            $endTo = ($this->meta['pageCurrent'] * $this->meta['pageSize']);
            $sql .=  sprintf(PHP_EOL."WHERE \"_rnum\" BETWEEN %s AND %s", $startFrom, $endTo);
        }
        return $sql;
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

    public function getMeta($key)
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : null;
    }

    public function getAllMeta()
    {
        return $this->meta;
    }

    public function getTotal($key)
    {
        return $this->getStatistic('total'.ucfirst($key));
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
        $size = $defaultSize;
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

    private function setMeta($key, $value)
    {
        $this->meta[$key] = $value;
    }

    public function getJson()
    {
        return json_encode($this->get());
    }
}
