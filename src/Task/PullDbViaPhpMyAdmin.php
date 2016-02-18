<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use Brads\Robo\Task\ImportSqlDump;
use GuzzleHttp\Client as Http;
use GuzzleHttp\TransferStats;
use function Stringy\create as s;
use Brads\Robo\Shared\PhpMyAdminLogin;
use Brads\Robo\Shared\PhpMyAdminLoginTask;
use Brads\Robo\Shared\PhpMyAdminListTables;

trait PullDbViaPhpMyAdmin
{
	protected function taskPullDbViaPhpMyAdmin(PhpMyAdminLoginTask $loggedIn = null)
	{
		return new PullDbViaPhpMyAdminTask($loggedIn);
	}
}

class PullDbViaPhpMyAdminTask extends BaseTask
{
	use loadTasks, DynamicParams, ImportSqlDump,
	PhpMyAdminLogin, PhpMyAdminListTables;

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
	private $localDbPass;

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
	 * Executes the PullDbViaPhpMyAdmin Task.
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

		// Get a list of tables from phpMyAdmin
		$result = $this->taskPhpMyAdminListTables($this->loggedIn)
			->remoteDbName($this->remoteDbName)
		->run();

		if (!$result->wasSuccessful())
		{
			throw new RuntimeException('Failed to get list of tabels!');
		}

		$tables = $result->getTask()->getTables();

		// Get sql dump
		$this->printTaskInfo('Downloading sql dump.');
		$sql = $this->loggedIn->getClient()->post('export.php',
		[
			'form_params' =>
			[
				'db' => $this->remoteDbName,
				'server' => $this->loggedIn->getServerId(),
				'token' => $this->loggedIn->getToken(),
				'export_type' => 'database',
				'export_method' => 'quick',
				'quick_or_custom' => 'quick',
				'template_id' => '',
				'what' => 'sql',
				'structure_or_data_forced' => 0,
				'table_select' => $tables,
				'table_structure' => $tables,
				'table_data' => $tables,
				'output_format' => 'sendit',
				'filename_template' => '@DATABASE@',
				'remember_template' => 'on',
				'charset_of_file' => 'utf-8',
				'compression' => 'none',
				'maxsize' => '',
				'sql_include_comments' => 'something',
				'sql_header_comment' => '',
				'sql_compatibility' => 'NONE',
				'sql_structure_or_data' => 'structure_and_data',
				'sql_create_table' => 'something',
				'sql_auto_increment' => 'something',
				'sql_create_view' => 'something',
				'sql_procedure_function' => 'something',
				'sql_create_trigger' => 'something',
				'sql_backquotes' => 'something',
				'sql_type' => 'INSERT',
				'sql_insert_syntax' => 'both',
				'sql_max_query_size' => '50000',
				'sql_hex_for_binary' => 'something',
				'sql_utc_time' => 'something'
			]
		])->getBody();

		// Create our dump filename
		$dump_name = tempnam(sys_get_temp_dir(), 'dump');

		// Save the dump
		$this->printTaskInfo('Saving dump - <info>'.$dump_name.'</info>');
		file_put_contents($dump_name, $sql);

		// Import the dump locally
		if (
			!$this->taskImportSqlDump($dump_name)
				->host($this->localDbHost)
				->user($this->localDbUser)
				->pass($this->localDbPass)
				->name($this->localDbName)
			->run()->wasSuccessful()
		){
			throw new RuntimeException('Failed to import dump on local server.');
		}

		$this->printTaskInfo('Deleting dump locally.');
		if (!unlink($dump_name))
		{
			return Result::error($this, 'Failed to delete dump on local server.');
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}
