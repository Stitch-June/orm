<?php

namespace EasySwoole\ORM;

use EasySwoole\Component\Singleton;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\Db\ClientInterface;
use EasySwoole\ORM\Db\ConnectionInterface;
use EasySwoole\ORM\Db\Result;
use EasySwoole\ORM\Exception\Exception;
use EasySwoole\Pool\Exception\PoolEmpty;
use Swoole\Coroutine;

/**
 * Class DbManager
 * @package EasySwoole\ORM
 */
class DbManager
{
    use Singleton;

    protected $connections = [];
    protected $transactionContext = [];
    protected $onQuery;

    public function onQuery(callable $call):DbManager
    {
        $this->onQuery = $call;
        return $this;
    }

    function addConnection(ConnectionInterface $connection,string $connectionName = 'default'):DbManager
    {
        $this->connections[$connectionName] = $connection;
        return $this;
    }

    function getConnection(string $connectionName = 'default'):?ConnectionInterface
    {
        if(isset($this->connections[$connectionName])){
            return $this->connections[$connectionName];
        }
        return null;
    }

    /**
     * @param QueryBuilder $builder
     * @param bool $raw
     * @param string|ClientInterface $connection
     * @param float|null $timeout
     * @return Result
     * @throws Exception
     * @throws \Throwable
     */
    function query(QueryBuilder $builder, bool $raw = false, $connection = 'default', float $timeout = null):Result
    {
        $cid = Coroutine::getCid();
        $isTransaction = isset($this->transactionContext[$cid]);

        if(is_string($connection)){
            $conTemp = $this->getConnection($connection);
            if(!$conTemp){
                throw new Exception("connection : {$connection} not register");
            }
            $client = $conTemp->defer($timeout);
            if(empty($client)){
                throw new PoolEmpty("connection : {$connection} is empty");
            }
        }else{
            $client = $connection;
        }
        // 判断是否为事务，是则处理client的事务标识
        $this->preTransactionIdentify($client, $builder, $raw);

        $start = microtime(true);
        $ret = $client->query($builder,$raw);
        if($this->onQuery){
            $temp = clone $builder;
            call_user_func($this->onQuery,$ret,$temp,$start);
        }
        if(in_array('SQL_CALC_FOUND_ROWS',$builder->getLastQueryOptions())){
            $temp = new QueryBuilder();
            $temp->raw('SELECT FOUND_ROWS() as count');
            $count = $client->query($temp,true);
            if($this->onQuery){
                call_user_func($this->onQuery,$count,$temp,$start,$client);
            }
            $ret->setTotalCount($count->getResult()[0]['count']);
        }

        // 非事务环境，并且非invoke注入客户端  同时配置了自动回收
        if (!$isTransaction && is_string($connection) && $this->getConnection($connection)->getConfig()->isAutoRecycleObj() ){
            $this->getConnection($connection)->getClientPool()->recycleObj($client);
        }

        return $ret;
    }


    function invoke(callable $call,string $connectionName = 'default',float $timeout = null)
    {
        $connection = $this->getConnection($connectionName);
        if($connection){
            $client = $connection->getClientPool()->getObj($timeout);
            if($client){
                try{
                    return call_user_func($call,$client);
                }catch (\Throwable $exception){
                    throw $exception;
                }finally{
                    $connection->getClientPool()->recycleObj($client);
                }
            }else{
                throw new PoolEmpty("connection : {$connectionName} is empty");
            }
        }else{
            throw new Exception("connection : {$connectionName} not register");
        }
    }

    /**
     * @param string|ClientInterface|array<string>|array<ClientInterface> $connections
     * @return bool
     * @throws Exception
     * @throws \Throwable
     */
    public function startTransaction($connections = 'default'):bool
    {
        if ($connections instanceof ClientInterface){
            return $connections->startTransaction();
        }

        if(!is_array($connections)){
            $connections = [$connections];
        }
        /*
         * 1、raw执行 start transaction
         * 2、若全部链接执行成功，则往transactionContext 标记对应协程的成功事务，并注册一个defer自动执行回滚,防止用户忘了提交导致死锁
         * 3、若部分链接成功，则成功链接执行rollback
         */
        $cid = Coroutine::getCid();
        foreach ($connections as $name) {
            //  先行设置事务管理器的状态（为了在query执行的时候 不回收客户端）
            $this->transactionContext[$cid][] = $name;
            $builder = new QueryBuilder();
            $builder->starTtransaction();
            $res = $this->query($builder,true,$name);
            if ($res->getResult() !== true){
                $this->clearTransactionContext($name);
                $this->rollback();
                return false;
            }
        }

        Coroutine::defer(function (){
            $cid = Coroutine::getCid();
            if(isset($this->transactionContext[$cid])){
                $this->rollback();
            }
        });
        return true;
    }

    public function commit($connectName = NULL):bool
    {
        if ($connectName instanceof ClientInterface){
            return $connectName->commit();
        }

        $cid = Coroutine::getCid();
        if(isset($this->transactionContext[$cid])){
            // 如果有指定
            if ($connectName !== NULL){
                $builder = new QueryBuilder();
                $builder->commit();
                $res = $this->query($builder,true,$connectName);
                if ($res->getResult() !== true){
                    $this->rollback($connectName);
                    return false;
                }
                $this->clearTransactionContext($connectName);
                return true;
            }
            foreach ($this->transactionContext[$cid] as $name){
                $builder = new QueryBuilder();
                $builder->commit();
                $res = $this->query($builder, true,$name);
                if ($res->getResult() !== true){
                    $this->rollback($name);
                    return false;
                }
            }
            $this->clearTransactionContext();
            return true;
        }
        return false;
    }


    public function rollback($connectName = NULL):bool
    {
        if ($connectName instanceof ClientInterface){
            return $connectName->rollback();
        }

        $cid = Coroutine::getCid();
        if(isset($this->transactionContext[$cid])){
            // 如果有指定
            if ($connectName !== NULL){
                $builder = new QueryBuilder();
                $builder->rollback();
                $res = $this->query($builder, true,$connectName);
                if ($res->getResult() !== true){
                    return false;
                }
                $this->clearTransactionContext($connectName);
                return true;
            }
            foreach ($this->transactionContext[$cid] as $name){
                $builder = new QueryBuilder();
                $builder->rollback();
                $res = $this->query($builder, true,$name);
                if ($res->getResult() !== true){
                    return false;
                }
            }
            $this->clearTransactionContext();
            return true;
        }
        return false;
    }

    protected function clearTransactionContext($connectName = null)
    {
        $cid = Coroutine::getCid();
        if (!isset($this->transactionContext[$cid])){
            return false;
        }

        if ($connectName !== null){
            foreach ($this->transactionContext[$cid] as $key => $name){
                if ($name === $connectName){
                    unset($this->transactionContext[$cid][$key]);
                    return true;
                }
                return false;
            }
        }

        unset($this->transactionContext[$cid]);
        return true;
    }

    /**
     * 预处理客户端储存事务标识状态
     * @param ClientInterface $client
     * @param QueryBuilder $builder
     * @param bool $raw
     * @return bool
     */
    private function preTransactionIdentify(ClientInterface $client, QueryBuilder $builder, bool $raw)
    {
        // 事务操作都是raw执行
        if (!$raw){
            return true;
        }
        $lastQuery = $builder->getLastQuery();

        // 判断build的lastQuey是否跟事务有关
        if ($lastQuery === "start transaction"){
            return $client->setTransactionStatus(true);
        }else if ($lastQuery === "commit"){
            return $client->setTransactionStatus(false);
        }else if ($lastQuery === "rollback"){
            return $client->setTransactionStatus(false);
        }

        return true;
    }

}
