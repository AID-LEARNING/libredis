<?php

namespace SenseiTarzan\libredis\Class;

class Response
{

	private static Response $empty;

	static public function getEmpty(): Response
	{
		if(!isset(self::$empty))
			self::$empty = new Response();
		return self::$empty;
	}

}