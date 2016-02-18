<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use Brads\Robo\Task\ImportSqlDump;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

trait PullDbViaSsh
{
	protected function taskPullDbViaSsh()
	{
		return new PullDbViaSshTask();
	}
}

class PullDbViaSshTask extends BaseTask
{
	use loadTasks, DynamicParams, ImportSqlDump;

	/** @var string */
	private $sshHost;

	/** @var string */
	private $sshUser;

	/** @var string */
	private $sshPass;

	/** @var string */
	private $sshKey;

	/** @var string */
	private $remoteDbHost = 'localhost';

	/** @var string */
	private $remoteDbUser = 'root';

	/** @var string */
	private $remoteDbPass;

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

	/**
	 * Executes the PullDbViaSsh Task.
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
		// Login to the remote server
		$this->printTaskInfo('Logging into remote server - <info>ssh://'.$this->sshUser.'@'.$this->sshHost.'/</info>');
		$ssh = new SFTP($this->sshHost);

		// Do we use password or a key
		if (file_exists($this->sshKey) && empty($this->sshPass))
		{
			$key = new RSA();
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
		$cmd = 'mysqldump '.
			'-h'.$this->remoteDbHost.
			' -u'.$this->remoteDbUser.
			' '.(empty($this->remoteDbPass) ? '' : '-p'.$this->remoteDbPass).
			' '.$this->remoteDbName.' > /tmp/'.$dump_name.'.sql'
		;
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
		return Result::success($this);
	}
}
