<?php

namespace SenseiTarzan\redistest;


use Redis;
use SenseiTarzan\libredis\Class\Request;
use SenseiTarzan\libredis\Class\Response;

class GetMememberFactionRequest extends Request
{
	public static function request(Redis $client, array $argv): Response
	{
		$data  = $client->sMembers("faction:123:members");
		$client->incrBy("test", 1);
		return new FindResponse($data);
	}
}
