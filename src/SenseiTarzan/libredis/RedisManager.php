<?php

namespace SenseiTarzan\libredis;

use AttachableLogger;
use Closure;
use Error;
use Exception;
use Generator;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\utils\Terminal;
use Redis;
use RedisException;
use ReflectionClass;
use SenseiTarzan\libredis\Class\RedisError;
use SenseiTarzan\libredis\Class\Request;
use SenseiTarzan\libredis\Class\Response;
use SenseiTarzan\libredis\Exception\QueueShutdownException;
use SenseiTarzan\libredis\Thread\QueryRecvQueue;
use SenseiTarzan\libredis\Thread\QuerySendQueue;
use SenseiTarzan\libredis\Thread\ThreadRedis;
use SOFe\AwaitGenerator\Await;
use SplFixedArray;

class RedisManager
{

	/**
	 * @var array<int, Closure>
	 */
	private array $handlers = [];
	private Redis $redis;

	/**@var array<ThreadRedis> */
	private array $workers;
	private QuerySendQueue $bufferSend;
	private QueryRecvQueue $bufferRecv;
	private readonly AttachableLogger $logger;
	private SleeperHandlerEntry $sleeperHandlerEntry;
	private static int $queryId = 0;
	private int $workerCount = 0;

	public function __construct(
		PluginBase $plugin,
		readonly array $config,
		readonly int $workerCountMax
	)
	{
		$this->logger = $plugin->getLogger();
		try {

			$this->redis = new Redis($this->config);
		}catch (\Throwable $exception) {
			throw new RedisError(RedisError::STAGE_CONNECT, $exception->getMessage(), $this->config);
		}
		$this->workers = [];
		$this->bufferSend = new QuerySendQueue();
		$this->bufferRecv = new QueryRecvQueue();
		$this->sleeperHandlerEntry = $plugin->getServer()->getTickSleeper()->addNotifier(function(): void {
			$this->checkResults();
		});
		$this->addWorker();
	}

	public function addWorker(): void
	{
		$this->workers[$this->workerCount++] =  new ThreadRedis($this->sleeperHandlerEntry, $this->bufferSend, $this->bufferRecv, $this->config);
	}

	public function stopRunning(): void
	{
		//$this->bufferSend->invalidate();
		foreach ($this->workers as $worker) {
			$worker?->quit();
		}
	}

	/**
	 * @throws RedisException
	 */
	public function close(): void
	{
		$this->stopRunning();
		$this->redis->close();
	}

	/**
	 * @param class-string<Request> $request
	 * @param array $argv
	 * @param callable|null $handler
	 * @param callable|null $onError
	 * @throws QueueShutdownException
	 */
	public function executeRequestOnThread(string $request, array $argv = [], ?callable $handler = null, ?callable $onError = null) : void{
		$queryId = self::$queryId++;
		$trace = libredis::isPackaged() ? null : new Exception("(This is the original stack trace for the following error)");
		$this->handlers[$queryId] = function(RedisError|Response $results) use ($handler, $onError, $trace){
			if($results instanceof RedisError){
				$this->reportError($onError, $results, $trace);
			}else{
				if ($handler === null)
					return ;
				try{
					$handler($results);
				}catch(Exception $e){
					if(!libredis::isPackaged()){
						$prop = (new ReflectionClass(Exception::class))->getProperty("trace");
						$newTrace = $prop->getValue($e);
						$oldTrace = $prop->getValue($trace);
						for($i = count($newTrace) - 1, $j = count($oldTrace) - 1; $i >= 0 && $j >= 0 && $newTrace[$i] === $oldTrace[$j]; --$i, --$j){
							array_pop($newTrace);
						}
						$prop->setValue($e, array_merge($newTrace, [
							[
								"function" => Terminal::$COLOR_YELLOW . "--- below is the original stack trace ---" . Terminal::$FORMAT_RESET,
							],
						], $oldTrace));
					}
					throw $e;
				}catch(Error $e){
					if(!libredis::isPackaged()){
						$exceptionProperty = (new ReflectionClass(Exception::class))->getProperty("trace");
						$oldTrace = $exceptionProperty->getValue($trace);

						$errorProperty = (new ReflectionClass(Error::class))->getProperty("trace");
						$newTrace = $errorProperty->getValue($e);

						for($i = count($newTrace) - 1, $j = count($oldTrace) - 1; $i >= 0 && $j >= 0 && $newTrace[$i] === $oldTrace[$j]; --$i, --$j){
							array_pop($newTrace);
						}
						$errorProperty->setValue($e, array_merge($newTrace, [
							[
								"function" => Terminal::$COLOR_YELLOW . "--- below is the original stack trace ---" . Terminal::$FORMAT_RESET,
							],
						], $oldTrace));
					}
					throw $e;
				}
			}
		};

		$this->addQuery($queryId, $request, $argv);
	}

	/**
	 * @param string $request
	 * @param array $argv
	 * @return Generator
	 * @throws QueueShutdownException
	 */
	public function asyncRequest(string $request, array $argv = []): Generator
	{
		$onSuccess = yield Await::RESOLVE;
		$onError = yield Await::REJECT;
		$this->executeRequestOnThread($request, $argv, $onSuccess, $onError);
		return yield Await::ONCE;
	}

	/**
	 * @param class-string<Request> $request
	 * @param array $argv
	 * @return Response
	 */
	public function syncRequest(string $request, array $argv = []): Response
	{
		return $request::run($this->redis, $argv);
	}


	/**
	 * @param int $queryId
	 * @param class-string<Request> $request
	 * @param array $argv
	 * @return void
	 * @throws QueueShutdownException
	 */
	private function addQuery(int $queryId, string $request, array $argv = []) : void{
		$this->bufferSend->scheduleQuery($queryId, $request, $argv);
		foreach ($this->workers as $worker) {
			if(!$worker->isBusy()){
				return;
			}
		}
		unset($iterator);
		if($this->workerCount < $this->workerCountMax){
			$this->addWorker();
		}
	}


	private function reportError(?callable $default, RedisError $error, ?Exception $trace) : void{
		if($default !== null){
			try{
				$default($error, $trace);
				$error = null;
			}catch(RedisError $err){
				$error = $err;
			}
		}
		if($error !== null){
			$this->logger->error($error->getMessage());
			if($error->getArgs() !== null){
				$this->logger->debug("Args: " . json_encode($error->getArgs()));
			}
			if($trace !== null){
				$this->logger->debug("Stack trace: " . $trace->getTraceAsString());
			}
		}
	}


	public function join(): void {
		/** @var ThreadRedis[] $worker */
		foreach($this->workers as $worker) {
			$worker->join();
		}
	}

	public function readResults(array &$callbacks, ?int $expectedResults): void {
		if($expectedResults === null ){
			$resultsLists = $this->bufferRecv->fetchAllResults();
		} else {
			$resultsLists = $this->bufferRecv->waitForResults($expectedResults);
		}
		foreach($resultsLists as [$queryId, $results]) {
			if(!isset($callbacks) || !isset($callbacks[$queryId])) {
				throw new \InvalidArgumentException("Missing handler for query (#$queryId)");
			}
			$callbacks[$queryId]($results);
			unset($callbacks[$queryId]);
		}
	}

	public function connCreated() : bool{
		return $this->workers[0]->connCreated();
	}

	public function hasConnError() : bool{
		return $this->workers[0]->hasConnError();
	}

	public function getConnError() : ?string{
		return $this->workers[0]->getConnError();
	}

	public function waitAll() : void{
		while(!empty($this->handlers)){
			$this->readResults($this->handlers, count($this->handlers));
		}
	}

	public function checkResults() : void{
		$this->readResults($this->handlers, null);
	}

	public function getLoad() : float{
		return $this->bufferSend->count() / (float) $this->workers->getSize();
	}
}