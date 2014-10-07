<?php namespace Brads\Robo\Task;

use Net_SFTP;
use Crypt_RSA;
use Robo\Result;
use Robo\Output;
use Robo\Task\Exec;
use Robo\Task\Shared\DynamicConfig;
use Robo\Task\Shared\TaskInterface;

trait PushDbViaSsh
{
	protected function taskPushDbViaSsh()
	{
		return new PushDbViaSshTask();
	}
}

class PushDbViaSshTask implements TaskInterface
{
	use Output;
	use Exec;
	use DynamicConfig;

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
				return Result::error($this, 'Failed to login via SSH using Key Based Auth.');
			}
		}
		else
		{
			if (!$ssh->login($this->sshUser, $this->sshPass))
			{
				return Result::error($this, 'Failed to login via SSH using Password Based Auth.');
			}
		}

		// Create our dump filename
		$dump_name = $this->localDbName.'_'.time();

		// Create our dump locally
		$cmd = 'mysqldump -h'.$this->localDbHost.' -u'.$this->localDbUser.' '.(empty($this->localDbPass) ? '' : '-p'.$this->localDbPass).' '.$this->localDbName.' > /tmp/'.$dump_name.'local.sql';
		$this->printTaskInfo('Dumping db on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			return Result::error($this, 'Failed to create dump locally.');
		}

		// Compress the dump
		$cmd = 'gzip /tmp/'.$dump_name.'local.sql';
		$this->printTaskInfo('Compressing dump on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			return Result::error($this, 'Failed to compress dump locally.');
		}

		// Copy it up
		$this->printTaskInfo('Transfering dump to remote.');
		if (!$ssh->put('/tmp/'.$dump_name.'.sql.gz', '/tmp/'.$dump_name.'local.sql.gz', NET_SFTP_LOCAL_FILE))
		{
			return Result::error($this, 'Failed to upload db dump.');
		}

		// Remove the dump from the local server
		$this->printTaskInfo('Removing dump from local server. - <info>/tmp/'.$dump_name.'local.sql.gz</info>');
		if (!unlink('/tmp/'.$dump_name.'local.sql.gz'))
		{
			return Result::error($this, 'Failed to delete dump from local.');
		}

		// Decompress dump on remote
		$cmd = 'gzip -d /tmp/'.$dump_name.'.sql.gz';
		$this->printTaskInfo('Decompressing dump on remote server - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			return Result::error($this, 'Failed to decompress dump on remote.', $results);
		}

		// Import db remotely
		$cmd = 'mysql -h'.$this->remoteDbHost.' -u'.$this->remoteDbUser.' '.(empty($this->remoteDbPass) ? '' : '-p'.$this->remoteDbPass).' '.$this->remoteDbName.' < /tmp/'.$dump_name.'.sql';
		$this->printTaskInfo('Importing dump remotely - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			return Result::error($this, 'Failed to import dump on remote.', $results);
		}

		// Delete dump from remote server
		$this->printTaskInfo('Removing dump from remote server. - <info>/tmp/'.$dump_name.'.sql</info>');
		if (!$ssh->delete('/tmp/'.$dump_name.'.sql'))
		{
			return Result::error($this, 'Failed to delete dump on remote.');
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}