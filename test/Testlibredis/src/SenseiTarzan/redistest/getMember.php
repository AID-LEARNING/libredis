<?php

namespace SenseiTarzan\redistest;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissionNames;
use SOFe\AwaitGenerator\Await;

class getMember extends Command
{

	public function __construct(string $name, Translatable|string $description = "", Translatable|string|null $usageMessage = null, array $aliases = [])
	{
		parent::__construct($name, $description, $usageMessage, $aliases);
		$this->setPermission(DefaultPermissionNames::GROUP_USER);
	}


	public function execute(CommandSender $sender, string $commandLabel, array $args)
	{
		$start = microtime(true);
		$data = Main::$instance->getManager()->syncRequest(GetMememberFactionRequest::class);
		echo "benchmak request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
		foreach ($data->getDataTest() as $test)
			$sender->sendMessage($test);
	}
}