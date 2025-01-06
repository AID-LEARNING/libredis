<?php

namespace SenseiTarzan\libredis\Thread;

use Closure;
use Composer\Autoload\ClassLoader;
use pmmp\thread\Thread as NativeThread;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use Redis;
use SenseiTarzan\libredis\Class\ETypeRequest;
use SenseiTarzan\libredis\Class\RedisError;
use SenseiTarzan\libredis\Class\Request;
use pocketmine\Server;
use SenseiTarzan\libredis\libredis;
use Throwable;

class ThreadRedis extends Thread
{

	private static int $nextSlaveNumber = 0;

	private readonly int $slaveId;
	private bool $busy = false;
	protected bool $connCreated = false;
	protected ?string $connError = null;
	private readonly string $config;

	public function __construct(
		private readonly SleeperHandlerEntry $sleeperEntry,
		private readonly QuerySendQueue      $bufferSend,
		private readonly QueryRecvQueue      $bufferRecv,
		array         		 $config
	)
	{
        $this->slaveId = self::$nextSlaveNumber++;
        if(!libredis::isPackaged()){
            /** @noinspection PhpUndefinedMethodInspection */
            /** @noinspection NullPointerExceptionInspection */
            /** @var ClassLoader $cl */
            $cl = Server::getInstance()->getPluginManager()->getPlugin("DEVirion")->getVirionClassLoader();
            $this->setClassLoaders([Server::getInstance()->getLoader(), $cl]);
        }
        $this->config = igbinary_serialize($config);
        $this->start(NativeThread::INHERIT_INI);
	}

	protected function onRun(): void
	{
		$notifier = $this->sleeperEntry->createNotifier();
        $runner_class = [];
		try {
			$client = new Redis(igbinary_unserialize($this->config));
			$this->connCreated = true;
		} catch (Throwable $exception){
			$this->connError = $exception;
			$this->connCreated = true;
			return;
		}
		while(true) {
            $row = $this->bufferSend->fetchQuery();
            if ($row === null)
                break ;
            $this->busy = true;
            /**
             * @var class-string<Request>|Closure($client, $argv): mixed $request
             */
            $queryId = $row[0];
            $type = ETypeRequest::tryFrom($row[1]);
            $request = $row[2];
            $argv = igbinary_unserialize($row[3]);
            try{
                if ($type === ETypeRequest::STRING_CLASS) {
                    if (!isset($runner_class[$request]))
                        $runner_class[$request] = $request::run(...);
                    $this->bufferRecv->publishResult($queryId, $runner_class[$request]($client, $argv));
                }else if ($type === ETypeRequest::CLOSURE) {
                    $this->bufferRecv->publishResult($queryId, $request($client, $argv));
                } else {
                    throw new RedisError(RedisError::STAGE_EXECUTE, "Unsupported type");
                }
            }catch(Throwable $error){
                $this->bufferRecv->publishError($queryId, new RedisError(RedisError::STAGE_RESPONSE, $error->getMessage(), $argv));
            }
            $notifier->wakeupSleeper();
			$this->busy = false;
		}
		$client->close();
	}
	public function stopRunning(): void {
		$this->bufferSend->invalidate();
	}

	/**
	 * @return int
	 */
	public function getSlaveId(): int
	{
		return $this->slaveId;
	}

	public function connCreated() : bool{
		return $this->connCreated;
	}

	public function hasConnError() : bool{
		return $this->connError !== null;
	}

	public function getConnError() : ?string{
		return $this->connError;
	}

	/**
	 * @return bool
	 */
	public function isBusy() : bool{
		return $this->busy;
	}

	public function quit() : void{
		$this->stopRunning();
		parent::quit();
	}
}