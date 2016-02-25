<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\FileSystem;
use Robo\Common\DynamicParams;
use Robo\Task\Base\loadTasks as BaseTasks;
use Robo\Task\FileSystem\loadTasks as FileTasks;
use Brads\Robo\Task\CreateDb;

trait ImportSqlDump
{
	protected function taskImportSqlDump($dump)
	{
		return new ImportSqlDumpTask($dump);
	}
}

class ImportSqlDumpTask extends BaseTask
{
	use BaseTasks, FileTasks, CreateDb, DynamicParams;

	/** @var string */
	private $host = 'localhost';

	/** @var string */
	private $user = 'root';

	/** @var string */
	private $pass = '';

	/** @var string */
	private $name;

	/** @var string */
	private $dump;

	/**
	 * This sets the location of the sql dump.
	 *
	 * @param string $dump The sql dump to import.
	 */
	public function __construct($dump)
	{
		$this->dump = $dump;
	}

	/**
	 * Executes the ImportSqlDump Task.
	 *
	 * @return Robo\Result
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
		return Result::success($this);
	}
}
