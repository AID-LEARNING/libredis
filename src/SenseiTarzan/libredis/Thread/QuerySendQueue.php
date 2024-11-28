<?php

namespace SenseiTarzan\libredis\Thread;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use SenseiTarzan\libredis\Class\Request;
use SenseiTarzan\libredis\Exception\QueueShutdownException;

class QuerySendQueue extends ThreadSafe
{
	/** @var bool */
	private bool $invalidated = false;
	/** @var ThreadSafeArray */
	private ThreadSafeArray $queries;

	public function __construct(){
		$this->queries = new ThreadSafeArray();
	}

	/**
	 * @param int $queryId
	 * @param class-string<Request> $request
	 * @param array $argv
	 * @return void
	 * @throws QueueShutdownException
	 */
	public function scheduleQuery(int $queryId, string $request, array $argv = []): void {
		if($this->invalidated){
			throw new QueueShutdownException("You cannot schedule a query on an invalidated queue.");
		}
		$this->synchronized(function() use ($queryId, $request, $argv) : void{
			$this->queries[] = serialize([$queryId, $request, $argv]);
			$this->notifyOne();
		});
	}

	public function fetchQuery() : ?string {
		return $this->synchronized(function(): ?string {
			while($this->queries->count() === 0 && !$this->isInvalidated()){
				$this->wait();
			}
			return $this->queries->shift();
		});
	}

	public function invalidate() : void {
		$this->synchronized(function():void{
			$this->invalidated = true;
			$this->notify();
		});
	}

	/**
	 * @return bool
	 */
	public function isInvalidated(): bool {
		return $this->invalidated;
	}

	public function count() : int{
		return $this->queries->count();
	}
}