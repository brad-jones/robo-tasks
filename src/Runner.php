<?php namespace Brads\Robo;

use RuntimeException;
use Robo\Config;
use Robo\Common\IO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;

class Runner extends \Robo\Runner
{
	public function execute($class = null)
	{
		// Stop 2 sets of errors from showing
		// see: http://stackoverflow.com/questions/9001911
		ini_set('log_errors', 1);
		ini_set('display_errors', 0);

		// I hate the warning PHP throws when no timezone is set.
		// date_default_timezone_get will return UTC if it can't find
		// anything else and we have supressed it's warning here so all
		// other calls to date / time functions shouldn't output errors. 
		date_default_timezone_set(@date_default_timezone_get());

		// Show the last PHP error that occured

		// NOTE: I have disabled this functionality on purpose.
		// My reasoning is that the PHP error will be logged
		// and / or displayed anyway. Also if you want to on purpose
		// hide some PHP notices in some legacy code or the date time
		// code abovefor example, this shutdown method stops us from
		// doing that cleanly.

		// register_shutdown_function([$this, 'shutdown']);

		// Share the output object
		Config::setOutput(new ConsoleOutput());

		// Make sure the class is actually loaded.
		if (!class_exists($class))
		{
			$this->getOutput()->writeln
			(
				'<error>'.
					'Class "'.$class.'" needs to be loaded or '.
					'be able to be loaded before calling this runner!'.
				'</error>'
			);

            exit(1);
		}

		// Create and run the robo cli application
		$app = $this->createApplication($class);
		$app->run($this->prepareInput($_SERVER['argv']));
	}
}