<?php

namespace SenseiTarzan\redistest;

use SenseiTarzan\libredis\Class\Response;

class FindResponse extends Response
{

	public function __construct(
		private array $data_test
	)
	{
	}

	/**
	 * @return int
	 */
	public function getDataTest(): array
	{
		return $this->data_test;
	}

}
