<?php namespace Brads\Robo\Task;

use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Common\DynamicParams;
use Stringy\Stringy as s;

trait SearchReplaceDb
{
	protected function taskSearchReplaceDb()
	{
		return new SearchReplaceDbTask();
	}
}

class SearchReplaceDbTask extends BaseTask
{
	use DynamicParams;

	/** @var string */
	private $dbHost = 'localhost';

	/** @var string */
	private $dbUser = 'root';

	/** @var string */
	private $dbPass;

	/** @var string */
	private $dbName;

	/** @var string */
	private $searchFor;

	/** @var string */
	private $replaceWith;

	/**
	 * If set only runs the script on the specified table(s).
	 * Provide an array for multiple values.
	 *
	 * @var mixed
	 */
	private $tables;

	/**
	 * If set only runs the script on the specified column(s).
	 * Provide an array for multiple values.
	 *
	 * @var mixed
	 */
	private $columns;

	/**
	 * If set to true, we consider the searchFor value a regular expression.
	 *
	 * @var boolean
	 */
	private $useRegx = false;

	/**
	 * If true we will only tell you what we would have replaced.
	 * No replacements will actually be made.
	 *
	 * @var boolean
	 */
	private $dryRun = false;

	/**
	 * If false we will not output anything.
	 *
	 * @var boolean
	 */
	private $verbose = false;

	/**
	 * Executes the SearchReplaceDb Task.
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
		// Build the command to run
		$cmd = 'php ./vendor/interconnectit/search-replace-db/srdb.cli.php ';

		// Add the the db details
		$cmd .= '--host "'.$this->dbHost.'" ';
		$cmd .= '--user "'.$this->dbUser.'" ';
		$cmd .= '--pass "'.$this->dbPass.'" ';
		$cmd .= '--name "'.$this->dbName.'" ';

		// Add the search term and replacement
		$cmd .= '--search "'.$this->searchFor.'" ';
		$cmd .= '--replace "'.$this->replaceWith.'" ';

		// Provide a custom set of tables to search and replace
		if (!empty($this->tables))
		{
			if (is_array($this->tables))
			{
				$this->tables = implode(',', $this->tables);
			}

			$cmd .= '--tables "'.$this->tables.'" ';
		}

		// Provide a custom set of columns to search and replace
		if (!empty($this->columns))
		{
			if (is_array($this->columns))
			{
				$this->columns = implode(',', $this->columns);
			}

			$cmd .= '--include-cols "'.$this->columns.'" ';
		}

		// Are we using regular expressions
		if ($this->useRegx) $cmd .= '--regex ';

		// Is it a dry run or not
		if ($this->dryRun) $cmd .= '--dry-run ';

		// Tell the world whats happening
		$this->printTaskInfo('running <info>'.$cmd.'</info>');

		// Run the cmd
		$descriptorspec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
		$process = proc_open($cmd, $descriptorspec, $pipes);
		if (is_resource($process))
		{
			$output = [];

			$output['stdout'] = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$output['stderr'] = stream_get_contents($pipes[2]);
			fclose($pipes[2]);

			proc_close($process);
		}
		else
		{
			return Result::error($this, 'Failed to run the command!');
		}

		// Remove the strict standard error
		$regx = '/(PHP\s)?Strict Standards:\s+Declaration of icit_srdb_cli::log.*?\d+/';
		$output['stdout'] = trim(s::create($output['stdout'])->regexReplace($regx, ''));
		$output['stderr'] = trim(s::create($output['stderr'])->regexReplace($regx, ''));

		// Check for errors
		if (!empty($output['stderr']))
		{
			return Result::error($this, $output['stderr']);
		}

		// Split stdout to an array of lines
		$output = explode("\n", $output['stdout']);

		// Remove the last 2 lines from the output
		// While the search and replace might be done, other tasks may not be.
		// Thus we might give the wrong impression of being complete.
		$status = array_pop($output); array_pop($output);

		// Do we want to output all the results
		if ($this->verbose)
		{
			foreach ($output as $line)
			{
				$this->printTaskInfo($line);
			}
		}
		else
		{
			// We only need to output the last 4 lines
			foreach (array_slice($output, -4) as $line)
			{
				$this->printTaskInfo($line);
			}
		}

		// Return success of failure
		if ($status == 'And we\'re done!')
		{
			return Result::success($this);
		}
		else
		{
			return Result::error($this, $status);
		}
	}
}
