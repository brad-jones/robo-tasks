<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Task\FileSystem;

trait ImportSqlDump
{
	protected function taskImportSqlDump($dump)
	{
		return new ImportSqlDumpTask($dump);
	}
}

class ImportSqlDumpTask extends \Robo\Task\BaseTask
{
	use \Robo\Task\Base\loadTasks;
	use \Robo\Task\FileSystem\loadTasks;
	use \Brads\Robo\Task\CreateDb;
	use \Robo\Common\DynamicParams;

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
		// Lets make sure we have a database
		if (!$this->taskCreateDb()
				->host($this->host)
				->user($this->user)
				->pass($this->pass)
				->name($this->name)
				->dropTables(true)
			->run()->wasSuccessful()
		){
			throw new RuntimeException('We failed to create the db.');
		}

		// Do we need to uncompress it first?
		if (strpos($this->dump, '.gz') !== false)
		{
			// Create a temp dump file
			$temp_dump = tempnam(sys_get_temp_dir(), 'dump');

			// Decompress the dump file
			if ($fp_out = fopen($temp_dump, 'wb'))
			{ 
				if ($fp_in = gzopen($this->dump, 'rb'))
				{ 
					while (!gzeof($fp_in))
					{
						fwrite($fp_out, gzread($fp_in, 1024 * 512));
					}

					fclose($fp_in); 
				}
				else
				{
					throw new RuntimeException
					(
						'Failed to open source dump file for reading.'
					);
				}

				gzclose($fp_out); 
			}
			else
			{
				throw new RuntimeException
				(
					'Failed to open temp dump file for writing.'
				);
			}

			// Set the dump the deflated version
			$this->dump = $temp_dump;

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
			throw new RuntimeException
			(
				'We failed to import your dump. '.
				'HINT: Is the `mysql` binary in your "PATH"?'
			);
		}

		// Delete the deflated temp dump file
		if (isset($delete_me))
		{
			$this->printTaskInfo('Deleting temp dump file.');
			if (!unlink($delete_me))
			{
				return Result::error($this, 'Couldn`t delete temp file.');
			}
		}

		// If we get to here assume everything worked
		return \Robo\Result::success($this);
	}
}