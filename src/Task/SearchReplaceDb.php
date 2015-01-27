<?php namespace Brads\Robo\Task;

use Gears\String as Str;

trait SearchReplaceDb
{
	protected function taskSearchReplaceDb()
	{
		return new SearchReplaceDbTask();
	}
}

class SearchReplaceDbTask extends \Robo\Task\BaseTask
{
	use \Robo\Common\DynamicParams;

	// The database to perform the search and replace on
	private $dbHost = 'localhost';
	private $dbUser = 'root';
	private $dbPass;
	private $dbName;

	// The whole point of this task :)
	private $searchFor;
	private $replaceWith;

	// If set only runs the script on the specified table(s).
	// Provide an array for multiple values.
	private $tables;

	// If set only runs the script on the specified column(s).
	// Provide an array for multiple values.
	private $columns;

	// If set to true, we consider the searchFor value a regular expression
	private $useRegx = false;

	// If true we will only tell you what we would have replaced.
	// No replacements will actually be made.
	private $dryRun = false;

	// If false we will not output anything
	private $verbose = false;

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
	 * Robo\Result
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
			return \Robo\Result::error($this, 'Failed to run the command!');
		}

		// Remove the strict standard error
		$regx = '/(PHP\s)?Strict Standards:\s+Declaration of icit_srdb_cli::log.*?\d+/';
		$output['stdout'] = trim(Str::replace($output['stdout'], $regx, '', true));
		$output['stderr'] = trim(Str::replace($output['stderr'], $regx, '', true));

		// Check for errors
		if (!empty($output['stderr']))
		{
			return \Robo\Result::error($this, $output['stderr']);
		}

		// Split stdout to an array of lines
		$output = Str::split($output['stdout'], "\n");

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
			return \Robo\Result::success($this);
		}
		else
		{
			return \Robo\Result::error($this, $status);
		}
	}
}