<?php namespace Brads\Robo\Task;

use RuntimeException;
use Net_SFTP;
use Crypt_RSA;

trait PullDbViaSsh
{
	protected function taskPullDbViaSsh()
	{
		return new PullDbViaSshTask();
	}
}

class PullDbViaSshTask extends \Robo\Task\BaseTask
{
	use \Robo\Task\Base\loadTasks;
	use \Brads\Robo\Task\ImportSqlDump;
	use \Robo\Common\DynamicParams;

	// Ssh details
	private $sshHost;
	private $sshUser;
	private $sshPass;
	private $sshKey;

	// The remote db details
	private $remoteDbHost = 'localhost';
	private $remoteDbUser = 'root';
	private $remoteDbPass;
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
		// Login to the remote server
		$this->printTaskInfo('Logging into remote server - <info>ssh://'.$this->sshUser.'@'.$this->sshHost.'/</info>');
		$ssh = new Net_SFTP($this->sshHost);

		// Do we use password or a key
		if (file_exists($this->sshKey) && empty($this->sshPass))
		{
			$key = new Crypt_RSA();
			$key->loadKey(file_get_contents($this->sshKey));
			if (!$ssh->login($this->sshUser, $key))
			{
				throw new RuntimeException
				(
					'Failed to login via SSH using Key Based Auth.'
				);
			}
		}
		else
		{
			if (!$ssh->login($this->sshUser, $this->sshPass))
			{
				throw new RuntimeException
				(
					'Failed to login via SSH using Password Based Auth.'
				);
			}
		}

		// Create our dump filename
		$dump_name = $this->remoteDbName.'_'.time();

		// Create our dump on the remote server
		$cmd = 'mysqldump -h'.$this->remoteDbHost.' -u'.$this->remoteDbUser.' '.(empty($this->remoteDbPass) ? '' : '-p'.$this->remoteDbPass).' '.$this->remoteDbName.' > /tmp/'.$dump_name.'.sql';
		$this->printTaskInfo('Dumping db on remote server - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			throw new RuntimeException
			(
				'Failed to create dump on remote server. '.
				$results
			);
		}
		
		// Compressing dump
		$cmd = 'gzip /tmp/'.$dump_name.'.sql';
		$this->printTaskInfo('Compressing dump on remote server - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			throw new RuntimeException
			(
				'Failed to compress dump on remote server. '.
				$results
			);
		}

		// Copy it down locally
		$this->printTaskInfo('Transfering dump to local.');
		$temp_dump_name = tempnam(sys_get_temp_dir(), 'dump');
		$temp_dump = $temp_dump_name.'.sql.gz';
		if (!$ssh->get('/tmp/'.$dump_name.'.sql.gz', $temp_dump))
		{
			throw new RuntimeException('Failed to download dump.');
		}

		// Remove the dump from the remote server
		$this->printTaskInfo('Removing dump from remote server - <info>rm /tmp/'.$dump_name.'.sql.gz</info>');
		if (!$ssh->delete('/tmp/'.$dump_name.'.sql.gz'))
		{
			throw new RuntimeException('Failed to delete dump on remote server.');
		}

		// Import the dump locally
		if (
			!$this->taskImportSqlDump($temp_dump)
				->host($this->localDbHost)
				->user($this->localDbUser)
				->pass($this->localDbPass)
				->name($this->localDbName)
			->run()->wasSuccessful()
		){
			throw new RuntimeException('Failed to import dump on local server.');
		}

		$this->printTaskInfo('Deleting dump locally.');
		unlink($temp_dump); unlink($temp_dump_name);

		// If we get to here assume everything worked
		return \Robo\Result::success($this);
	}
}