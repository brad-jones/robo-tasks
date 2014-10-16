<?php namespace Brads\Robo\Task;

use RuntimeException;
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
		$dump_name = tempnam(sys_get_temp_dir(), 'dump');

		// Create our dump locally
		$cmd = 'mysqldump -h'.$this->localDbHost.' -u'.$this->localDbUser.' '.(empty($this->localDbPass) ? '' : '-p'.$this->localDbPass).' '.$this->localDbName.' > '.$dump_name;
		$this->printTaskInfo('Dumping db on local server - <info>'.$cmd.'</info>');
		if (!$this->taskExec($cmd)->run()->wasSuccessful())
		{
			throw new RuntimeException
			(
				'Failed to create dump locally.'.
				'HINT: Is the `mysqldump` binary in your "PATH"?'
			);
		}

		// Compress the dump
		$this->printTaskInfo('Compressing dump on local server - <info>'.$cmd.'</info>');
		if ($fp_out = gzopen($dump_name.'.gz', 'wb9'))
		{ 
			if ($fp_in = fopen($dump_name, 'rb'))
			{ 
				while (!feof($fp_in))
				{
					gzwrite($fp_out, fread($fp_in, 1024 * 512));
				}

				fclose($fp_in); 
			}
			else
			{
				throw new RuntimeException
				(
					'Failed to open source dump file for reading.'
				);
			}

			gzclose($fp_out); 
		}
		else
		{
			throw new RuntimeException
			(
				'Failed to open destination compressed dump file for writing.'
			);
		}

		// Copy it up
		$this->printTaskInfo('Transfering dump to remote.');
		$dump_name_remote = '/tmp/'.$this->remoteDbName.'-'.time().'.sql';
		if (!$ssh->put($dump_name_remote.'.gz', $dump_name, NET_SFTP_LOCAL_FILE))
		{
			throw new RuntimeException('Failed to upload db dump.');
		}

		// Decompress dump on remote
		$cmd = 'gzip -d '.$dump_name_remote.'.gz';
		$this->printTaskInfo('Decompressing dump on remote server - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			throw new RuntimeException('Failed to decompress dump on remote.');
		}

		// Import db remotely
		$cmd = 'mysql -h'.$this->remoteDbHost.' -u'.$this->remoteDbUser.' '.(empty($this->remoteDbPass) ? '' : '-p'.$this->remoteDbPass).' '.$this->remoteDbName.' < '.$dump_name_remote;
		$this->printTaskInfo('Importing dump remotely - <info>'.$cmd.'</info>');
		$results = $ssh->exec($cmd);
		if ($ssh->getExitStatus() > 0)
		{
			throw new RuntimeException('Failed to import dump on remote.');
		}

		// Delete dump from remote server
		$this->printTaskInfo('Removing dump from remote server. - <info>'.$dump_name_remote.'</info>');
		if (!$ssh->delete($dump_name_remote))
		{
			return Result::error($this, 'Failed to delete dump on remote.');
		}

		// Remove the dump from the local server
		$this->printTaskInfo('Removing dump from local server. - <info>'.$dump_name.'</info>');
		if (!unlink($dump_name))
		{
			return Result::error($this, 'Failed to delete dump from local.');
		}

		// If we get to here assume everything worked
		return Result::success($this);
	}
}