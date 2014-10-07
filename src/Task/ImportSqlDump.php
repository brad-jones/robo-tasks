<?php namespace Brads\Robo\Task;

use Robo\Result;
use Robo\Output;
use Robo\Task\Exec;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;

trait ImportSqlDump
{
	protected function taskImportSqlDump($dump)
	{
		return new ImportSqlDumpTask($dump);
	}
}

class ImportSqlDumpTask implements TaskInterface
{
	use Exec;
	use Output;
	use CreateDb;
	use DynamicConfig;

	// The database details
	private $host = 'localhost';
	private $user = 'root';
	private $pass = '';
	private $name;

	// The location of the dump to import
	private $dump;

	/**
	 * Method: __construct
	 * =========================================================================
	 * This sets the location of the sql dump.
	 * 
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * $query - The sql dump to import.
	 * 
	 * Returns:
	 * -------------------------------------------------------------------------
	 * void
	 */
	public function __construct($dump)
	{
		$this->dump = $dump;
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
	 * Robo\Result
	 */
	public function run()
	{
		// Let make sure we have a database
		if (!
			$this->taskCreateDb()
				->host($this->host)
				->user($this->user)
				->pass($this->pass)
				->name($this->name)
				->dropTables(true)
			->run()
		){
			return Result::error($this, 'CreateDb Failed');
		}

		// Do we need to uncompress it first?
		if (strpos($this->dump, '.gz') !== false)
		{
			// Lets copy the dump to a temp location
			// and leave the original untouched
			$temp_dump = tempnam(sys_get_temp_dir(), 'dump').'.sql.gz';
			if (!$this->taskExec('cp '.$this->dump.' '.$temp_dump)->run()->wasSuccessful())
			{
				return Result::error($this, 'Copy Failed');
			}

			// Now lets deflate the dump
			if (!$this->taskExec('gzip -d '.$temp_dump)->run()->wasSuccessful())
			{
				return Result::error($this, 'Deflate Failed');
			}

			// Set the dump the delated version
			$this->dump = str_replace('.gz', '', $temp_dump);

			// Delete the temp later
			$delete_me = $this->dump;
		}

		// Construct the command to import the dump
		$cmd = 'mysql -h'.$this->host.' -u'.$this->user;
		if (!empty($this->pass)) $cmd .= ' -p'.$this->pass;
		$cmd .= ' '.$this->name.' < '.$this->dump;

		// Run the command
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			return Result::error($this, 'Import Failed');
		}

		// Delete the defalted temp dump file
		if (isset($delete_me))
		{
			$this->printTaskInfo('Deleting temp dump file.');
			if (!unlink($delete_me))
			{
				return Result::error($this, 'Couldn`t delete temp file.');
			}
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}