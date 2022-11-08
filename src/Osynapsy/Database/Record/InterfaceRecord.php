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
    const BEHAVIOR_INSERT = 'insert';
    const BEHAVIOR_UPDATE = 'update';

    public function fieldExists($field);

    public function findByKey($key);

    public function findByAttributes(array $searchParameters);

    public function get($key = null);

    public function getBehavior();

    public function reset();

    public function save(array $values = []);

    public function setValue($field, $value = null, $defaultValue = null);

    public function setValues(array $values);
}
