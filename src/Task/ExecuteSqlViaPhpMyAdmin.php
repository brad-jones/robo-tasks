<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use GuzzleHttp\Client as Http;
use GuzzleHttp\TransferStats;
use function Stringy\create as s;
use Brads\Robo\Shared\PhpMyAdminLogin;
use Brads\Robo\Shared\PhpMyAdminLoginTask;

trait ExecuteSQLViaPhpMyAdmin
{
	protected function taskExecuteSqlViaPhpMyAdmin($query, PhpMyAdminLoginTask $loggedIn = null)
	{
		return new ExecuteSqlViaPhpMyAdminTask($query, $loggedIn);
	}
}

class ExecuteSqlViaPhpMyAdminTask extends BaseTask
{
	use loadTasks, DynamicParams, PhpMyAdminLogin;

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
	private $query;

	/** @var PhpMyAdminLoginTask */
    private $loggedIn;

	/**
	 * This sets our query property.
	 *
	 * @param string              $query    The sql query to perform.
	 * @param PhpMyAdminLoginTask $loggedIn An already logged in task.
	 */
	public function __construct($query, PhpMyAdminLoginTask $loggedIn = null)
	{
		$this->query = $query;
		$this->loggedIn = $loggedIn;
	}

	/**
	 * Executes the ExecuteSqlViaPhpMyAdmin Task.
	 *
	 * Example usage:
	 * ``php
	 * 	$this->taskExecuteSqlViaPhpMyAdmin('sql goes here')
	 * 		->phpMyAdminUrl('http://example.org/phpmyadmin/')
	 * 		->phpMyAdminUser('...')
	 * 		->phpMyAdminPass('...')
	 * 		->remoteDbHost('localhost')
	 * 		->remoteDbName('mydb')
	 * 	->run();
	 * ```
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

		// Execute our sql
		$this->printTaskInfo('Executing the query.');
		$response = $this->loggedIn->getClient()->post('import.php',
		[
			'form_params' =>
			[
				'db' => $this->remoteDbName,
				'server' => $this->loggedIn->getServerId(),
				'token' => $this->loggedIn->getToken(),
				'sql_query' => $this->query,
				'sql_delimiter' => ';'

			]
		])->getBody();

		// Check to make sure it worked
		if (!$this->confirmSuccessfulImport($response))
		{
			// Save the response to a temp file for later inspection
			$responseLog = tempnam(sys_get_temp_dir(), 'phpMyAdminResponse');
			file_put_contents($responseLog, $response);

			// Bail out
			throw new RuntimeException
			(
				'Your query failed. '.
				'A log of the complete HTTP response has been saved to: '.
				$responseLog
			);
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}

	/**
	 * Given a html response we will check for certian phrases
	 * to suggest if the sql executed succesfully or not.
	 *
	 * @param  string $html
	 * @return boolean
	 */
	private function confirmSuccessfulImport($html)
	{
		$html = s($html);

		if ($html->contains('Your SQL query has been executed successfully'))
		{
			if ($html->contains('Query took'))
			{
				return true;
			}
		}

		return false;
	}
}
