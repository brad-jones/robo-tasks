<?php namespace Brads\Robo\Task;

use mysqli;
use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Common\DynamicParams;

trait CreateDb
{
	protected function taskCreateDb()
	{
		return new CreateDbTask();
	}
}

class CreateDbTask extends BaseTask
{
	use DynamicParams;

	/** @var string */
	private $host = 'localhost';

	/** @var string */
	private $user = 'root';

	/** @var string */
	private $pass = '';

	/** @var string */
	private $name;

	/** @var boolean */
	private $dropTables = false;

	/**
	 * Executes the CreateDb Task.
	 *
	 * Example usage:
	 * ```php
	 * $this->taskCreateDb()
	 * 		->host('my.db.host')
	 * 		->user('my_db_user')
	 * 		->pass('P@ssw0rd')
	 * 		->name('the_db_to_create')
	 * ->run();
	 * ```
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
		// Login to the db
		$this->printTaskInfo('Connecting to db server - <info>mysql://'.$this->user.':'.$this->pass.'@'.$this->host.'</info>');
		$db = new mysqli($this->host, $this->user, $this->pass);
		if ($db->connect_errno)
		{
			throw new RuntimeException
			(
				'Failed to connect to MySQL: ('.$db->connect_errno.') '.
				$db->connect_error
			);
		}

		// Create the db
		$query = 'CREATE DATABASE IF NOT EXISTS `'.$this->name.'`';
		$this->printTaskInfo('Running query - <info>'.$query.'</info>');
		if (!$db->query($query))
		{
			throw new RuntimeException
			(
				'Failed to create database: ('.$db->errorno.') '.
				$db->error
			);
		}

		// Do we want to drop all the tables as well
		if ($this->dropTables)
		{
			$this->printTaskInfo('Cleaning database of all data!');

			// Select db
			if (!$db->select_db($this->name))
			{
				throw new RuntimeException
				(
					'Failed to select database: ('.$db->errorno.') '.
					$db->error
				);
			}

			// Make sure we don't get foreign key errors
			$query = 'SET foreign_key_checks = 0';
			$this->printTaskInfo('Running query - <info>'.$query.'</info>');
			if (!$db->query($query))
			{
				throw new RuntimeException
				(
					'Query failed - '.$query.': ('.$db->errorno.') '.
					$db->error
				);
			}

			// Drop all our local data
			if ($result = $db->query("SHOW TABLES"))
			{
				while($row = $result->fetch_array(MYSQLI_NUM))
				{
					$query = 'DROP TABLE IF EXISTS '.$row[0];
					$this->printTaskInfo('Running query - <info>'.$query.'</info>');
					if (!$db->query($query))
					{
						throw new RuntimeException
						(
							'Failed to drop table - '.$row[0].': ('.$db->errorno.') '.
							$db->error
						);
					}
				}
			}

			// Put the foreign key checks back on
			$query = 'SET foreign_key_checks = 1';
			$this->printTaskInfo('Running query - <info>'.$query.'</info>');
			if(!$db->query($query))
			{
				throw new RuntimeException
				(
					'Query failed - '.$query.': ('.$db->errorno.') '.
					$db->error
				);
			}

			// Close connection to db
			$db->close();
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}
