<?php namespace Brads\Robo\Task;

use Robo\Result;
use Robo\Output;
use Robo\Task\Exec;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;
use GuzzleHttp\Client as Guzzle;
use Gears\String as Str;

trait PullDbViaPhpMyAdmin
{
	protected function taskPullDbViaPhpMyAdmin()
	{
		return new PullDbViaPhpMyAdminTask();
	}
}

class PullDbViaPhpMyAdminTask implements TaskInterface
{
	use Output;
	use Exec;
	use DynamicConfig;
	use ImportSqlDump;

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

		// Get sql dump
		$this->printTaskInfo('Downloading sql dump.');
		$sql = $http->post('export.php',
		[
			'body' =>
			[
				'db' => $this->remoteDbName,
				'server' => $server_id,
				'token' => $token,
				'export_type' => 'database',
				'export_method' => 'quick',
				'quick_or_custom' => 'quick',
				'what' => 'sql',
				'table_select' => $tables,
				'output_format' => 'sendit',
				'filename_template' => '@DATABASE@',
				'remember_template' => 'on',
				'charset_of_file' => 'utf-8',
				'compression' => 'none',
				'sql_include_comments' => 'something',
				'sql_header_comment' => '',
				'sql_compatibility' => 'NONE',
				'sql_structure_or_data' => 'structure_and_data',
				'sql_create_table' => 'something',
				'sql_create_view' => 'something',
				'sql_procedure_function' => 'something',
				'sql_create_trigger' => 'something',
				'sql_create_table_statements' => 'something',
				'sql_if_not_exists' => 'something',
				'sql_auto_increment' => 'something',
				'sql_backquotes' => 'something',
				'sql_type' => 'INSERT',
				'sql_insert_syntax' => 'both',
				'sql_max_query_size' => '50000',
				'sql_hex_for_blob' => 'something',
				'sql_utc_time' => 'something'
			]
		])->getBody();

		// Create our dump filename
		$dump_name = $this->localDbName.'_'.time();

		// Save the dump
		$this->printTaskInfo('Saving dump - <info>/tmp/'.$dump_name.'.sql</info>');
		file_put_contents('/tmp/'.$dump_name.'.sql', $sql);

		// Import the dump locally
		if (
			!$this->taskImportSqlDump('/tmp/'.$dump_name.'.sql')
				->host($this->localDbHost)
				->user($this->localDbUser)
				->pass($this->localDbPass)
				->name($this->localDbName)
			->run()->wasSuccessful()
		){
			return Result::error($this, 'Failed to import dump on local server.');
		}

		$this->printTaskInfo('Deleting dump locally.');
		if (!unlink('/tmp/'.$dump_name.'.sql'))
		{
			return Result::error($this, 'Failed to delete dump on local server.');
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}