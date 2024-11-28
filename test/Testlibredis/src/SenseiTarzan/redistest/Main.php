<?php

namespace SenseiTarzan\redistest;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;
use SenseiTarzan\libredis\libredis;
use SenseiTarzan\libredis\RedisManager;
use SOFe\AwaitGenerator\Await;

class Main extends PluginBase implements Listener
{

	public static  Main $instance;
	private RedisManager|null $manager;

	protected function onLoad(): void
	{
		self::$instance = $this;
	}


	/**
	 */
	public function onEnable(): void {
		$this->manager = libredis::create($this, [
			"host" => "127.0.0.1",
			"port" => 6379,
			"persistent" => true
		]);
		$this->getServer()->getCommandMap()->register("test", new getMember("testredis", "test redis"));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}


	public function onChat(PlayerChatEvent $event)
	{
		$event->cancel();
		$start = microtime(true);
		$data = $this->manager->syncRequest(GetMememberFactionRequest::class);
		echo "benchmak request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
	}

	public function onJoin(PlayerJoinEvent $event): void
	{
		$start = microtime(true);
		$data = $this->manager->syncRequest(GetMememberFactionRequest::class);
		echo "benchmak request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
		foreach ($data->getDataTest() as $test)
			$event->getPlayer()->sendMessage($test);
	}


	public function onQuit(PlayerQuitEvent $event): void
	{
		Await::f2c(function () use ($event) {
			$start = microtime(true);
			$promises =[];
			for ($i = 0; $i < 10; ++$i)
				$promises[] = $this->manager->asyncRequest(GetMememberFactionRequest::class);
			yield from Await::all($promises);
			return (microtime(true) - $start) * 1000;
		}, function (float|int $time) {
			echo $time . PHP_EOL;
		});
	}

	/**
	 * @return RedisManager|null
	 */
	public function getManager(): ?RedisManager
	{
		return $this->manager;
	}



	protected function onDisable(): void
	{
		$this->manager->waitAll();
		$this->manager->close();
	}
}