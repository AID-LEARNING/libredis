<?php

namespace SenseiTarzan\libredis\Thread;

use Closure;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use SenseiTarzan\libredis\Class\ETypeRequest;
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
     * @param ETypeRequest|string $type
     * @param string|Closure $request
     * @param ?array $argv
     * @return void
     */
	public function scheduleQuery(int $queryId, ETypeRequest|string $type, string|Closure $request, ?array $argv = null): void {
		if($this->invalidated){
			throw new QueueShutdownException("You cannot schedule a query on an invalidated queue.");
		}
		$this->synchronized(function() use ($queryId, $type, $request, $argv) : void{
            $this->queries[] = ThreadSafeArray::fromArray([$queryId, is_string($type) ? $type : $type->value, $request, igbinary_serialize($argv)]);
			$this->notifyOne();
		});
	}

	public function fetchQuery() : ?ThreadSafeArray {
		return $this->synchronized(function(): ?ThreadSafeArray {
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