<?php namespace Brads\Robo;

use Robo\Config;
use Robo\Application;
use Robo\Runner as RoboRunner;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * A custom runner that does not go looking for an actual "RoboFile.php"
 *
 * @see https://github.com/Codegyre/Robo/pull/242
 *
 * Example Usage:
 * -----------------------------------------------------------------------------
 * ```php
 * 	// This class could be autoloaded for example.
 * 	class MyRoboClass
 * 	{
 * 		// tasks...
 * 	}
 *
 * 	// To run robo
 * 	(new Brads\Robo\Runner(MyRoboClass::class))->execute();
 * ```
 */
class Runner extends RoboRunner
{
	public function execute($input = null)
	{
        register_shutdown_function(array($this, 'shutdown'));
        set_error_handler(array($this, 'handleError'));
        Config::setOutput(new ConsoleOutput());
        $input = $this->prepareInput($input ? $input : $_SERVER['argv']);
        $app = new Application('Robo', self::VERSION);
        $app->addCommandsFromClass($this->roboClass, $this->passThroughArgs);
        $app->run($input);
	}
}
