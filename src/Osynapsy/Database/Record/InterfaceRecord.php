<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Db\Record;

/**
 * Description of InterfaceRecord
 *
 * @author pietr
 */
interface InterfaceRecord
{
    public function fieldExists($field);

    public function findByKey($key);

    public function findByAttributes(array $searchParameters);

    public function get($key = null);

    public function setValue($field, $value = null, $defaultValue = null);
}
