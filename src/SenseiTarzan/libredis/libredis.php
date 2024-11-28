<?php

namespace SenseiTarzan\libredis;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Terminal;
use SenseiTarzan\libredis\Class\RedisError;

class libredis
{



	/** @var bool */
	private static bool $packaged;

	public static function isPackaged() : bool{
		return self::$packaged;
	}

	public static function detectPackaged() : void{
		self::$packaged = __CLASS__ !== 'SenseiTarzan\libredis\libredis';

		if(!self::$packaged && defined("pocketmine\\VERSION")){
			echo Terminal::$COLOR_YELLOW . "Warning: Use of unshaded libmongodm detected. Debug mode is enabled. This may lead to major performance drop. Please use a shaded package in production. See https://poggit.pmmp.io/virion for more information.\n";
		}
	}

	/**
	 * @param PluginBase $plugin
	 * @param array $configData
	 * @param int $workerLimit
	 * @return RedisManager
	 */
	public static function create(PluginBase $plugin,  array $configData, int $workerLimit = 2) : RedisManager{
		libredis::detectPackaged();
		$manager = new RedisManager($plugin, $configData, $workerLimit);
		while(!$manager->connCreated()){
			usleep(1000);
		}
		if($manager->hasConnError()){
			throw new RedisError(RedisError::STAGE_CONNECT, $manager->getConnError());
		}
		return $manager;
	}
}