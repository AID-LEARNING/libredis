<?php

namespace SenseiTarzan\libredis\Class;

use Redis;

abstract class Request
{


	public static function run(Redis $client, array $argv): Response
	{
		try {
			return static::request($client, $argv);
		}
		catch (\Throwable $e) {
			throw new RedisError(RedisError::STAGE_EXECUTE, $e->getMessage(), $argv);
		}
	}
	protected static function request(Redis $client, array $argv): Response{
		return Response::getEmpty();
	}
}