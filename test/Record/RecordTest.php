<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Osynapsy\Database\DboFactory;
use Osynapsy\Database\Record\Active as RecordActive;

final class RecordTest extends TestCase
{
    private function getRecord()
    {
        return new class([], [], $this->getConnection()) extends RecordActive
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
        $Factory->getConnection(0)->insert('tbl_client', ['frt_nam' => 'Giacomo', 'lst_nam' => 'Leopardi']);
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
        $record->where(['1']);
        $this->assertEquals('update', $record->getBehavior());
    }
    
    public function testInsert()
    {
        $record = $this->getRecord();
        $id = $record->save(['id' => null, 'frt_nam' => 'Giuseppe', 'lst_nam' => 'Garibaldi']);
        $this->assertEquals($id, '3');
        $this->assertEquals($record->getBehavior(), 'update');
    }

    public function testBehavoirOnFailRetriveActiveRecord()
    {
        $record = $this->getRecord();
        $record->where(['id' => '99']);
        $this->assertEquals('insert', $record->getBehavior());
    }
    
    public function xtestgetIdRecord()
    {
        $record = $this->getRecord();
        $record->where(['id' => '1']);
        $this->assertEquals('insert', $record->getBehavior());
    }

    public function xtestSetAliasField()
    {
        $record = $this->getRecord();
        $record->firstName = 'Pippo';
        $this->assertEquals($record->firstName, 'Pippo');
    }

    public function xtestSetSaveAliasField()
    {
        $record = $this->getRecord();
        $record->reset('1');
        $record->firstName = 'Pippo';
        $record->save();
        $record->reset();
        $record->reset('1');
        $this->assertEquals($record->frt_nam, 'Pippo');
    }
}
