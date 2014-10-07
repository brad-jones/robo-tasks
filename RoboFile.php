<?php

// PHP will throw out warnings which mucks up our
// unit testing if we don't set a timezone explicitly.
date_default_timezone_set('UTC');

/*
 * Include our local composer autoloader just in case
 * we are called with a globally installed version of robo.
 */
require_once(__DIR__.'/vendor/autoload.php');

class RoboFile extends Brads\Robo\Tasks
{
	/**
	 * This will run our unit / acceptance testing.
	 * Just run: ```php ./vendor/bin/robo test```
	 */
	public function test()
	{
		$this->yell('Setting up environment for unit tests.');

		$this->taskExecStack()
			->exec('sudo useradd --create-home --base-dir /home "robotasks"')
			->exec('echo "robotasks:GdtfF5Tm" | sudo chpasswd')
		->run();

		$this->taskFileSystemStack()->copy
		(
			'./vendor/phpmyadmin/phpmyadmin/config.sample.inc.php',
			'./vendor/phpmyadmin/phpmyadmin/config.inc.php',
			true
		)->run();

		$this->taskReplaceInFile('./vendor/phpmyadmin/phpmyadmin/config.inc.php')
			->from('$cfg[\'Servers\'][$i][\'AllowNoPassword\'] = false;')
			->to
			(
				'$cfg[\'Servers\'][$i][\'AllowNoPassword\'] = true;'."\n".
				'$i++; $cfg[\'Servers\'][$i][\'host\'] = \'localhost2\';'
			)
		->run();

		$this->taskFileSystemStack()->mkdir('/tmp/sftpsynctest')->run();
		$this->taskExec('sudo chmod 0777 /tmp/sftpsynctest')->run();

		$this->taskServer(8000)
			->dir('./vendor/phpmyadmin/phpmyadmin')
			->background(true)
		->run();

		$this->taskServer(9000)
			->dir('/tmp/sftpsynctest')
			->background(true)
		->run();

		$this->yell('Running unit tests.');

		$this->taskPHPUnit()->arg('./tests')->run();

		$this->yell('Cleaning up after tests.');

		$this->say('Removing test databases.');
		$db = new mysqli('127.0.0.1', 'root', '');
		$db->query('DROP DATABASE `myapp_test`;');
		$db->query('DROP DATABASE `myapp_test_pulled`;');
		$db->query('DROP DATABASE `myapp_test_pulled_ssh`;');

		$this->say('Removing test ssh user.');
		$this->taskExec('sudo killall -KILL -u robotasks')->run();
		$this->taskExec('sudo userdel -r robotasks')->run();

		$this->say('Removing sftpsynctest folder.');
		$this->taskExec('sudo chmod -R 0777 /tmp/sftpsynctest')->run();
		$this->taskFileSystemStack()->remove('/tmp/sftpsynctest')->run();
	}

	/*
	 * NOTE: The following methods / commands below here are called via phpunit.
	 * They should not be called directly. The way the unit tests have been
	 * setup, most of the db related tests rely upon the results of previous
	 * tests. This probably isn't 100% ideal but at least when something
	 * goes wrong, we know something has gone wrong in a big way.
	 */

	public function testCreateDb()
	{
		$this->taskCreateDb()
			->host('127.0.0.1')
			->user('root')
			->pass('')
			->name('myapp_test')
		->run();
	}

	public function testExecuteSqlViaPhpMyAdmin()
	{
		$this->taskExecuteSqlViaPhpMyAdmin('CREATE TABLE test (id INT, foo VARCHAR(255));')
			->phpMyAdminUrl('http://127.0.0.1:8000/')
			->phpMyAdminUser('root')
			->phpMyAdminPass('')
			->remoteDbHost('localhost')
			->remoteDbName('myapp_test')
		->run();
	}

	public function testImportSqlDump()
	{
		$this->taskImportSqlDump('./tests/data/dump.sql')
			->host('127.0.0.1')
			->user('root')
			->pass('')
			->name('myapp_test')
		->run();
	}

	public function testPullDbViaPhpMyAdmin()
	{
		$this->taskPullDbViaPhpMyAdmin()
			->phpMyAdminUrl('http://127.0.0.1:8000/')
			->phpMyAdminUser('root')
			->phpMyAdminPass('')
			->remoteDbHost('localhost')
			->remoteDbName('myapp_test')
			->localDbHost('127.0.0.1')
			->localDbUser('root')
			->localDbPass('')
			->localDbName('myapp_test_pulled')
		->run();
	}

	public function testPushDbViaPhpMyAdmin()
	{
		$this->taskPushDbViaPhpMyAdmin()
			->phpMyAdminUrl('http://127.0.0.1:8000/')
			->phpMyAdminUser('root')
			->phpMyAdminPass('')
			->remoteDbHost('localhost')
			->remoteDbName('myapp_test')
			->localDbHost('127.0.0.1')
			->localDbUser('root')
			->localDbPass('')
			->localDbName('myapp_test_pulled')
		->run();
	}

	public function testPullDbViaSsh()
	{
		$this->taskPullDbViaSsh()
			->sshHost('127.0.0.1')
			->sshUser('robotasks')
			->sshPass('GdtfF5Tm')
			->remoteDbHost('127.0.0.1')
			->remoteDbUser('root')
			->remoteDbPass('')
			->remoteDbName('myapp_test')
			->localDbHost('127.0.0.1')
			->localDbUser('root')
			->localDbPass('')
			->localDbName('myapp_test_pulled_ssh')
		->run();
	}

	public function testPushDbViaSsh()
	{
		$this->taskPullDbViaSsh()
			->sshHost('127.0.0.1')
			->sshUser('robotasks')
			->sshPass('GdtfF5Tm')
			->remoteDbHost('127.0.0.1')
			->remoteDbUser('root')
			->remoteDbPass('')
			->remoteDbName('myapp_test')
			->localDbHost('127.0.0.1')
			->localDbUser('root')
			->localDbPass('')
			->localDbName('myapp_test_pulled_ssh')
		->run();
	}

	public function testSftpSync()
	{
		$this->taskSftpSync()
			->sftpHost('127.0.0.1')
			->sftpUser('robotasks')
			->sftpPass('GdtfF5Tm')
			->localPath('./src')
			->remotePath('/tmp/sftpsynctest')
			->httpHost('127.0.0.1:9000')
		->run();
	}
}