<?php namespace Brads\Robo\Task;

use Closure;
use Robo\Result;
use Robo\Output;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;
use SuperClosure\SerializableClosure;
use SuperClosure\ClosureParser\Options;

trait WordpressSandbox
{
	protected function taskWordpressSandbox(Closure $closure)
	{
		return new WordpressSandboxTask($closure);
	}
}

class WordpressSandboxTask implements TaskInterface
{
	use Output;
	use DynamicConfig;

	private $closure;

	public function __construct(Closure $closure)
	{
		$this->closure = $closure;
	}

	/**
	 * Method: run
	 * =========================================================================
	 * The main run method.
	 * 
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * n/a
	 * 
	 * Returns:
	 * -------------------------------------------------------------------------
	 * mixed
	 */
	public function run()
	{
		// Serialize the closure.
		$serialized = \SuperClosure\serialize
		(
			$this->closure,
			[Options::TURBO_MODE => true]
		);

		// Create some cross platform temp filenames
		$temp_serialized_file = tempnam(sys_get_temp_dir(), 'wpSandBoxSerialized');
		$temp_eval_file = tempnam(sys_get_temp_dir(), 'wpSandBoxEval').'.php';

		// Create our temp eval file
		$php = '<?php $c = unserialize(file_get_contents("'.$temp_serialized_file.'")); echo json_encode($c());';

		// Save our tmp files
		file_put_contents($temp_serialized_file, $serialized);
		file_put_contents($temp_eval_file, $php);

		// Build the command to run
		$cmd = './vendor/bin/wp eval-file '.$temp_eval_file;

		// Run the command
		exec($cmd, $output);

		// Delete the tmp files
		unlink($temp_serialized_file);
		unlink($temp_eval_file);

		// Unserialize the output
		return json_decode($output[0]);
	}
}