<?php namespace Brads\Robo\Task;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

trait PushDbViaSsh
{
	protected function taskPushDbViaSsh()
	{
		return new PushDbViaSshTask();
	}
}

class PushDbViaSshTask extends BaseTask
{
	use loadTasks, DynamicParams;

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
	 * Executes the PushDbViaSsh Task.
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
		$dump_name = tempnam(sys_get_temp_dir(), 'dump');

		// Create our dump locally
		$cmd = 'mysqldump'.
			' -h'.$this->localDbHost.
			' -u'.$this->localDbUser.
			' '.(empty($this->localDbPass) ? '' : '-p'.$this->localDbPass).
			' '.$this->localDbName.' > '.$dump_name
		;
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
		if (!$ssh->put($dump_name_remote.'.gz', $dump_name, SFTP::SOURCE_LOCAL_FILE))
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
		$cmd = 'mysql'.
			' -h'.$this->remoteDbHost.
			' -u'.$this->remoteDbUser.
			' '.(empty($this->remoteDbPass) ? '' : '-p'.$this->remoteDbPass).
			' '.$this->remoteDbName.' < '.$dump_name_remote
		;
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
