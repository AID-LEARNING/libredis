<?php

namespace SenseiTarzan\libredis\Thread;

use Composer\Autoload\ClassLoader;
use pmmp\thread\Thread as NativeThread;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;
use pocketmine\utils\Limits;
use Redis;
use SenseiTarzan\libredis\Class\RedisError;
use SenseiTarzan\libredis\Class\Request;
use pocketmine\Server;
use SenseiTarzan\libredis\libredis;
use Throwable;

class ThreadRedis extends Thread
{
	private const REDIS_TPS = 5;
	private const REDIS_TIME_PER_TICK = 1 / self::REDIS_TPS;

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
		try {
			$client = new Redis(igbinary_unserialize($this->config));
			$this->connCreated = true;
		} catch (Throwable $exception){
			$this->connError = $exception;
			$this->connCreated = true;
			return;
		}
		while(true) {
			$start = microtime(true);
			$this->busy = true;
			for ($i = 0; $i < 100; ++$i){
				$row = $this->bufferSend->fetchQuery();
				if (!is_string($row)) {
					$this->busy = false;
					break 2;
				}
				/**
				 * @var class-string<Request> $request
				 */
				[$queryId, $request, $argv] = unserialize($row, ['allowed_classes' => true]);
				try{
						$this->bufferRecv->publishResult($queryId, $request::run($client, $argv));
				}catch(RedisError $error){
					$this->bufferRecv->publishError($queryId, $error);
				}
				$notifier->wakeupSleeper();
			}
			$this->busy = false;
			$time = microtime(true) - $start;
			if($time < self::REDIS_TIME_PER_TICK){
				@time_sleep_until(microtime(true) + self::REDIS_TIME_PER_TICK - $time);
			}
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