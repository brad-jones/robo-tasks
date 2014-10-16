<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Output;
use Robo\Task\Exec;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;
use GuzzleHttp\Client as Guzzle;
use Gears\String as Str;

trait ExecuteSQLViaPhpMyAdmin
{
	protected function taskExecuteSqlViaPhpMyAdmin($query)
	{
		return new ExecuteSqlViaPhpMyAdminTask($query);
	}
}

class ExecuteSqlViaPhpMyAdminTask implements TaskInterface
{
	use Output;
	use Exec;
	use DynamicConfig;

	// The PhpMyAdmin details
	private $phpMyAdminUrl;
	private $phpMyAdminUser;
	private $phpMyAdminPass;

	// The remote db details
	private $remoteDbHost;
	private $remoteDbName;

	// The query to run
	private $query;

	/**
	 * Method: __construct
	 * =========================================================================
	 * This sets our query property.
	 * 
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * $query - The sql query to perform.
	 * 
	 * Returns:
	 * -------------------------------------------------------------------------
	 * void
	 */
	public function __construct($query)
	{
		$this->query = $query;
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
		// Setup guzzle client
		$http = new Guzzle
		([
			'base_url' => $this->phpMyAdminUrl,
			'defaults' =>
			[
				'cookies' => true,
				'verify' => false
			]
		]);

		// Tell the world whats happening
		$this->printTaskInfo
		(
			'Logging into phpmyadmin - <info>'.
			str_replace
			(
				'://',
				'://'.$this->phpMyAdminUser.':'.$this->phpMyAdminPass.'@',
				$this->phpMyAdminUrl
			).'</info>'
		);

		// Make an intial request so we can extract some info
		$html = $http->get()->getBody();

		// Grab the token
		preg_match('#<input type="hidden" name="token" value="(.*?)" />#s', $html, $matches);
		$token = $matches[1];

		// Get the server id
		preg_match('#<option value="(\d+)".*?>'.preg_quote($this->remoteDbHost, '#').'.*?</option>#', $html, $matches);
		$server_id = $matches[1];

		// Login - session saved to cookie by guzzle
		$response = $http->post(null,
		[
			'body' =>
			[
				'pma_username' => $this->phpMyAdminUser,
				'pma_password' => $this->phpMyAdminPass,
				'server' => $server_id,
				'token' => $token
			]
		]);

		// Check to see if we passed auth
		if (!Str::contains($response->getEffectiveUrl(), $token))
		{
			throw new RuntimeException('phpMyAdmin Login Failed');
		}

		// Execute our sql
		$this->printTaskInfo('Executing the query.');
		$response = $http->post('import.php',
		[
			'body' =>
			[
				'db' => $this->remoteDbName,
				'server' => $server_id,
				'token' => $token,
				'sql_query' => $this->query,
				'sql_delimiter' => ';'

			]
		])->getBody();

		// Check to make sure it worked
		if (!Str::contains($response, 'Your SQL query has been executed successfully') && !Str::contains($response, 'Query took'))
		{
			// Save the response to a temp file for later inspection
			$responseLog = tempnam(sys_get_temp_dir(), 'taskExecuteSqlViaPhpMyAdminLog');
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
}