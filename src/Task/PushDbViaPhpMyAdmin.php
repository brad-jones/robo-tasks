<?php namespace Brads\Robo\Task;

use Robo\Result;
use Robo\Output;
use Robo\Task\Exec;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;
use GuzzleHttp\Client as Guzzle;
use Gears\String as Str;

trait PushDbViaPhpMyAdmin
{
	protected function taskPushDbViaPhpMyAdmin()
	{
		return new PushDbViaPhpMyAdminTask();
	}
}

class PushDbViaPhpMyAdminTask implements TaskInterface
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

	// The local db details
	private $localDbHost = 'localhost';
	private $localDbUser = 'root';
	private $localDbPass;
	private $localDbName;

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
			return Result::error($this, 'LOGIN FAILED');
		}

		// Create our dump filename
		$dump_name = $this->localDbName.'_'.time();

		// Create our dump locally
		$cmd = 'mysqldump -h'.$this->localDbHost.' -u'.$this->localDbUser.' '.(empty($this->localDbPass) ? '' : '-p'.$this->localDbPass).' '.$this->localDbName.' > /tmp/'.$dump_name.'.sql';
		$this->printTaskInfo('Dumping db on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			return Result::error($this, 'Failed to create dump locally.');
		}

		// Compress the dump
		$cmd = 'gzip /tmp/'.$dump_name.'.sql';
		$this->printTaskInfo('Compressing dump on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			return Result::error($this, 'Failed to compress dump locally.');
		}

		// Grab a list of tables
		$this->printTaskInfo('Getting list of tables.');
		$html = $http->get('db_structure.php',
		[
			'query' =>
			[
				'db' => $this->remoteDbName,
				'server' => $server_id,
				'token' => $token
			]
		])->getBody();

		preg_match_all('/'.preg_quote('<tr', '/').'.*?'.preg_quote('>', '/').'(.*?)'.preg_quote('</tr>', '/').'/s', $html, $matches);
		$tables = []; $matches = $matches[1]; array_shift($matches);
		foreach ($matches as $value)
		{
			preg_match('/<a href=".*?">(.*?)<\/a>/', $value, $submatch);
			if (isset($submatch[1])) $tables[] = $submatch[1];
		}

		// Check to see if we have any tables
		if (count($tables) > 0)
		{
			// Create the sql query to drop all those tables
			$sql = 'SET foreign_key_checks = 0; DROP TABLE ';
			foreach ($tables as $table) $sql .= $table.', ';
			$sql = substr($sql, 0, -2).'; SET foreign_key_checks = 1;';

			// Droping the tables
			$this->printTaskInfo('Droping tables from phpmyadmin.');
			$response = $http->post('import.php',
			[
				'body' =>
				[
					'db' => $this->remoteDbName,
					'server' => $server_id,
					'token' => $token,
					'sql_query' => $sql,
					'sql_delimiter' => ';'

				]
			])->getBody();

			// Check to make sure it worked
			if (!Str::contains($response, 'Your SQL query has been executed successfully') && !Str::contains($response, 'Query took'))
			{
				return Result::error($this, 'Failed to drop tables via phpmyadmin.');
			}
		}

		// Upload the dump
		$this->printTaskInfo('Uploading sql dump.');
		$response = $http->post('import.php',
		[
			'body' =>
			[
				'db' => $this->remoteDbName,
				'server' => $server_id,
				'token' => $token,
				'import_type' => 'database',
				'file_location' => 'on',
				'import_file' => fopen('/tmp/'.$dump_name.'.sql.gz', 'r'),
				'MAX_FILE_SIZE' => '209715200',
				'charset_of_file' => 'utf-8',
				'allow_interrupt' => 'yes',
				'skip_queries' => 0,
				'format' => 'sql',
				'sql_compatibility' => 'NONE',
				'sql_no_auto_value_on_zero' => 'something'
			]
		])->getBody();

		// Check that it worked
		if (!Str::contains($response, 'Import has been successfully finished'))
		{
			return Result::error($this, 'Failed to import dump via phpmyadmin. OH NO - we just dropped all the tables!!!');
		}

		// Remove the dump from the local server
		$this->printTaskInfo('Removing dump from local server. - <info>/tmp/'.$dump_name.'.sql.gz</info>');
		if (!unlink('/tmp/'.$dump_name.'.sql.gz'))
		{
			return Result::error($this, 'Failed to delete dump from local.');
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}