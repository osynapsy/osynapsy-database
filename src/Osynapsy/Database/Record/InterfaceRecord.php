<?php

/*
 * This file is part of the Osynapsy package.
 *
 * (c) Pietro Celeste <p.celeste@osynapsy.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osynapsy\Database\Record;

/**
 * Description of InterfaceRecord
 *
 * @author Pietro Celeste <p.celeste@osynapsy.net>
 */
interface InterfaceRecord
{
    public function fieldExists($field);

    public function findByKey($key);

    public function findByAttributes(array $searchParameters);

    public function get($key = null);

    public function setValue($field, $value = null, $defaultValue = null);
}
