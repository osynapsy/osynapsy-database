<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Osynapsy\Database\DboFactory;
use Osynapsy\Database\Record\Active2 as RecordActive;

final class RecordTest extends TestCase
{
    private function getRecord()
    {
        return new class($this->getConnection()) extends RecordActive
        {
            public function table()
            {
                return 'tbl_client';
            }

            public function primaryKey()
            {
                return ['id'];
            }

            public function fields()
            {
                return ['id', 'firstName' => 'frt_nam', 'lastName' => 'lst_nam'];
            }
        };
    }

    private function getConnection()
    {
        $Factory = new DboFactory();
        $Factory->createConnection('sqlite::memory:');
        $Factory->getConnection(0)->execCommand("CREATE TABLE tbl_client (id INTEGER PRIMARY KEY AUTOINCREMENT, frt_nam varchar(20), lst_nam varchar(20)); ");
        $Factory->getConnection(0)->insert('tbl_client', ['frt_nam' => 'Giuseppe', 'lst_nam' => 'Garibaldi']);
        $Factory->getConnection(0)->insert('tbl_client', ['frt_nam' => 'Giuseppe', 'lst_nam' => 'Verdi']);
        return $Factory->getConnection(0);
    }

    public function testBehavoirOnInit()
    {
        $record = $this->getRecord();
        $this->assertEquals($record->getBehavior(), 'insert');
    }

    public function testBehavoirOnSuccessRetriveActiveRecord()
    {
        $record = $this->getRecord();
        $record->findByAttributes(['id' => '1']);
        $this->assertEquals($record->getBehavior(), 'update');
    }

    public function testBehavoirOnFailRetriveActiveRecord()
    {
        $record = $this->getRecord();
        $record->findByAttributes(['id' => '4']);
        $this->assertEquals($record->getBehavior(), 'insert');
    }

    public function testSetAliasField()
    {
        $record = $this->getRecord();
        $record->firstName = 'Pippo';
        $this->assertEquals($record->firstName, 'Pippo');
    }

    public function testSetSaveAliasField()
    {
        $record = $this->getRecord();
        $record->findByKey('1');
        $record->firstName = 'Pippo';
        $record->save();
        $record->reset();
        $record->findByKey('1');
        $this->assertEquals($record->firstName, 'Pippo');
    }
}
