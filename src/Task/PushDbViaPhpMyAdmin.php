<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use GuzzleHttp\Client as Http;
use GuzzleHttp\TransferStats;
use Stringy\Stringy as s;
use Brads\Robo\Task\ExecuteSQLViaPhpMyAdmin;
use Brads\Robo\Shared\PhpMyAdminLogin;
use Brads\Robo\Shared\PhpMyAdminLoginTask;
use Brads\Robo\Shared\PhpMyAdminListTables;

trait PushDbViaPhpMyAdmin
{
	protected function taskPushDbViaPhpMyAdmin(PhpMyAdminLoginTask $loggedIn = null)
	{
		return new PushDbViaPhpMyAdminTask($loggedIn);
	}
}

class PushDbViaPhpMyAdminTask extends BaseTask
{
	use loadTasks, DynamicParams, PhpMyAdminLogin,
	PhpMyAdminListTables, ExecuteSQLViaPhpMyAdmin;

	/** @var string */
	private $phpMyAdminUrl;

	/** @var string */
	private $phpMyAdminUser;

	/** @var string */
	private $phpMyAdminPass;

	/** @var string */
	private $remoteDbHost;

	/** @var string */
	private $remoteDbName;

	/** @var string */
	private $localDbHost = 'localhost';

	/** @var string */
	private $localDbUser = 'root';

	/** @var string */
	private $localDbPass = 'root';

	/** @var string */
	private $localDbName;

	/** @var PhpMyAdminLoginTask */
    private $loggedIn;

	/**
	 * @param PhpMyAdminLoginTask $loggedIn An already logged in task.
	 */
	public function __construct(PhpMyAdminLoginTask $loggedIn = null)
	{
		$this->loggedIn = $loggedIn;
	}

	/**
	 * Executes the PushDbViaPhpMyAdmin Task.
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
		// First lets login to the phpMyAdmin Server, if not already.
		if ($this->loggedIn == null)
		{
			$result = $this->taskPhpMyAdminLogin()
				->phpMyAdminUrl($this->phpMyAdminUrl)
				->phpMyAdminUser($this->phpMyAdminUser)
				->phpMyAdminPass($this->phpMyAdminPass)
				->remoteDbHost($this->remoteDbHost)
			->run();

			if (!$result->wasSuccessful())
			{
				throw new RuntimeException('Failed to Login!');
			}

			$this->loggedIn = $result->getTask();
		}

		// Create our dump filename
		$dump_name = tempnam(sys_get_temp_dir(), 'dump');

		// Create our dump locally
		$cmd = 'mysqldump '.
			'-h'.$this->localDbHost.
			' -u'.$this->localDbUser.
			' '.(empty($this->localDbPass) ? '' : '-p'.$this->localDbPass).
			' '.$this->localDbName.' > '.$dump_name
		;

		$this->printTaskInfo('Dumping db on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			throw new RuntimeException
			(
				'Failed to create dump locally.'.
				'HINT: Is the `mysqldump` binary in your "PATH"?'
			);
		}

		// Compress the dump
		$this->printTaskInfo('Compressing dump on local server - <info>'.$cmd.'</info>');
		if ($fp_out = gzopen($dump_name.'.gz', 'wb9'))
		{
			if ($fp_in = fopen($dump_name, 'rb'))
			{
				while (!feof($fp_in))
				{
					gzwrite($fp_out, fread($fp_in, 1024 * 512));
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
				'Failed to open destination compressed dump file for writing.'
			);
		}

		// Get a list of tables from phpMyAdmin
		$result = $this->taskPhpMyAdminListTables($this->loggedIn)
			->remoteDbName($this->remoteDbName)
		->run();

		if (!$result->wasSuccessful())
		{
			throw new RuntimeException('Failed to get list of tabels!');
		}

		$tables = $result->getTask()->getTables();

		// Check to see if we have any tables
		if (count($tables) > 0)
		{
			// Create the sql query to drop all those tables
			$sql = 'SET foreign_key_checks = 0; DROP TABLE ';
			foreach ($tables as $table) $sql .= $table.', ';
			$sql = substr($sql, 0, -2).'; SET foreign_key_checks = 1;';

			// Droping the tables
			$this->printTaskInfo('Droping tables from phpmyadmin.');
			$result = $this->taskExecuteSqlViaPhpMyAdmin($sql, $this->loggedIn)
				->remoteDbName($this->remoteDbName)
			->run();

			// Check to make sure it worked
			if (!$result->wasSuccessful())
			{
				throw new RuntimeException('Failed to drop tables via phpmyadmin.');
			}
		}

		// Upload the dump
		$this->printTaskInfo('Uploading sql dump.');
		$response = $this->loggedIn->getClient()->post('import.php',
		[
			'multipart' =>
			[
				[
					'name' => 'db',
					'contents' => $this->remoteDbName
				],
				[
					'name' => 'server',
					'contents' => $this->loggedIn->getServerId()
				],
				[
					'name' => 'token',
					'contents' => $this->loggedIn->getToken()
				],
				[
					'name' => 'import_type',
					'contents' => 'database'
				],
				[
					'name' => 'file_location',
					'contents' => 'on'
				],
				[
					'name' => 'import_file',
					'contents' => fopen($dump_name.'.gz', 'r')
				],
				[
					'name' => 'charset_of_file',
					'contents' => 'utf-8'
				],
				[
					'name' => 'allow_interrupt',
					'contents' => 'yes'
				],
				[
					'name' => 'skip_queries',
					'contents' => '0'
				],
				[
					'name' => 'format',
					'contents' => 'sql'
				],
				[
					'name' => 'sql_compatibility',
					'contents' => 'NONE'
				],
				[
					'name' => 'sql_no_auto_value_on_zero',
					'contents' => 'something'
				]
			]
		])->getBody();

		// Check that it worked
		if (!s::create($response)->contains('Import has been successfully finished'))
		{
			throw new RuntimeException('Failed to import dump via phpmyadmin. OH NO - we just dropped all the tables!!!');
		}

		// Remove the dump from the local server
		$this->printTaskInfo('Removing dump from local server. - <info>'.$dump_name.'</info>');
		unlink($dump_name); unlink($dump_name.'.gz');

		// If we get to here assume everything worked
		return Result::success($this);
	}
}
