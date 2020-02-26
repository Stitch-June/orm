<?php
/**
 * 错误测试
 * User: Siam
 * Date: 2019/11/22
 * Time: 14:47
 */

namespace EasySwoole\ORM\Tests;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\Db\Config;
use EasySwoole\ORM\Db\Connection;
use EasySwoole\ORM\DbManager;
use EasySwoole\ORM\Exception\Exception;
use PHPUnit\Framework\TestCase;


use EasySwoole\ORM\Tests\models\TestUserModel;

/**
 * Class ErrorTest
 * @package EasySwoole\ORM\Tests
 */
class ErrorTest extends TestCase
{
    /**
     * @var $connection Connection
     */
    protected $connection;
    protected $tableName = 'user_test_list';
    protected function setUp()
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $config = new Config(MYSQL_CONFIG);
        $this->connection = new Connection($config);
        DbManager::getInstance()->addConnection($this->connection);
        $connection = DbManager::getInstance()->getConnection();
        $this->assertTrue($connection === $this->connection);
    }


    public function testGet()
    {
        try {
            $model = TestUserModel::create();
            $testUserModel = $model->where("xxx", 1)->get("");
            $this->assertFalse($testUserModel);
            if ($model->lastQueryResult()->getLastErrorNo() !== 0 ){
                $this->assertIsString($model->lastQueryResult()->getLastError());
            }
        } catch (Exception $e) {
            $this->assertEquals("SQLSTATE[42S22] [1054] Unknown column 'xxx' in 'where clause'", $e->getMessage());
        } catch (\Throwable $e) {
        }

    }

    public function testSave()
    {
        // insert 不存在的字段
        try {
            $model = TestUserModel::create();
            $model->test = 123;
            $res = $model->save(false, false);
        } catch (Exception $e) {
            $this->assertEquals("SQLSTATE[42S22] [1054] Unknown column 'test' in 'field list'", $e->getMessage());
        } catch (\Throwable $e) {
        }
    }

    // 全部字段为null  id自增
    public function testSaveNull()
    {// 没有准备表结构 本地临时测试通过
        // $model = TestUserListModel::create();
        // $res = $model->save();
        // $this->assertIsInt($res);
    }

    public function testThrow()
    {
        // 不存在的字段 where 抛出异常
        try {
            $test = TestUserModel::create()->get([
                'fuck_life' => 1
            ]);
        }catch (Exception $e) {
            $this->assertEquals("SQLSTATE[42S22] [1054] Unknown column 'fuck_life' in 'where clause'", $e->getMessage());
        } catch (\Throwable $e) {
        }

    }
}