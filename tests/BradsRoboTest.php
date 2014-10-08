<?php

class BradsRoboTest extends PHPUnit_Framework_TestCase
{
	public function testCreateDb()
	{
		$results = $this->callRoboTask('test:create-db');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '');
		$this->assertTrue($db->select_db('myapp_test'));
	}

	public function testExecuteSqlViaPhpMyAdmin()
	{
		$results = $this->callRoboTask('test:execute-sql-via-php-my-admin');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test');
		$results = $db->query('DESCRIBE test;')->fetch_all();
		$this->assertEquals('id', $results[0][0]);
		$this->assertEquals('int(11)', $results[0][1]);
		$this->assertEquals('foo', $results[1][0]);
		$this->assertEquals('varchar(255)', $results[1][1]);
	}

	public function testImportSqlDump()
	{
		$results = $this->callRoboTask('test:import-sql-dump');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test');
		$results = $db->query('SHOW tables;')->fetch_all();
		$this->assertEquals('wp_commentmeta', $results[0][0]);
	}

	public function testPullDbViaPhpMyAdmin()
	{
		$results = $this->callRoboTask('test:pull-db-via-php-my-admin');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test_pulled');
		$results = $db->query('SHOW tables;')->fetch_all();
		$this->assertEquals('wp_commentmeta', $results[0][0]);
	}

	public function testPushDbViaPhpMyAdmin()
	{
		$db = new mysqli('127.0.0.1', 'root', '');
		$db->query('DROP DATABASE `myapp_test`;');
		$db->query('CREATE DATABASE `myapp_test`;');

		$results = $this->callRoboTask('test:push-db-via-php-my-admin');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test');
		$results = $db->query('SHOW tables;')->fetch_all();
		$this->assertEquals('wp_commentmeta', $results[0][0]);
	}

	public function testPullDbViaSsh()
	{
		$results = $this->callRoboTask('test:pull-db-via-ssh');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test_pulled_ssh');
		$results = $db->query('SHOW tables;')->fetch_all();
		$this->assertEquals('wp_commentmeta', $results[0][0]);
	}

	public function testPushDbViaSsh()
	{
		$db = new mysqli('127.0.0.1', 'root', '');
		$db->query('DROP DATABASE `myapp_test_pulled_ssh`;');
		$db->query('CREATE DATABASE `myapp_test_pulled_ssh`;');

		$results = $this->callRoboTask('test:push-db-via-ssh');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test_pulled_ssh');
		$results = $db->query('SHOW tables;')->fetch_all();
		$this->assertEquals('wp_commentmeta', $results[0][0]);
	}

	public function testSearchReplaceDb()
	{
		$results = $this->callRoboTask('test:search-replace-db');

		$this->assertEmpty($results['stderr']);

		$db = new mysqli('127.0.0.1', 'root', '', 'myapp_test');
		$results = $db->query('SELECT `option_value` FROM `wp_options` WHERE `option_name` = "siteurl"')->fetch_all();
		$this->assertEquals('http://taskSearchReplaceDb', $results[0][0]);
	}

	public function testSftpSync()
	{
		$results = $this->callRoboTask('test:sftp-sync');

		$results1 = [];
		$finder1 = new Symfony\Component\Finder\Finder();
		foreach ($finder1->files()->in('./src') as $item)
		{
			$results1[] = $item->getRelativePath();
		}

		$results2 = [];
		$finder2 = new Symfony\Component\Finder\Finder();
		foreach ($finder2->files()->in('/tmp/sftpsynctest') as $item)
		{
			$results2[] = $item->getRelativePath();
		}

		$this->assertSame($results1, $results2);
	}

	private function callRoboTask($task)
	{
		$cmd = 'php ./vendor/bin/robo '.$task;
		$descriptorspec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
		$process = proc_open($cmd, $descriptorspec, $pipes);

		if (is_resource($process))
		{
			$output = [];

			$output['stdout'] = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			$output['stderr'] = stream_get_contents($pipes[2]);
			fclose($pipes[2]);

			proc_close($process);

			return $output;
		}
		else
		{
			throw new Exception('Failed to start process.');
		}
	}
}
