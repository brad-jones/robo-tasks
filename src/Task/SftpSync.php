<?php namespace Brads\Robo\Task;

use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Common\DynamicParams;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

/*
 * NOTE: The exact same thing could be done with FTP,
 * just have to find a half decent FTP package for PHP
 */

trait SftpSync
{
	protected function taskSftpSync()
	{
		return new SftpSyncTask();
	}
}

class SftpSyncTask extends BaseTask
{
	use DynamicParams;

	/** @var boolean */
	private $dryRun = false;

	/** @var string */
	private $sftpHost;

	/** @var string */
	private $sftpUser;

	/** @var string */
	private $sftpPass;

	/** @var string */
	private $sftpKey;

	/** @var string */
	private $localPath;

	/** @var string */
	private $remotePath;

	/** @var string */
	private $httpHost;

	/** @var array */
	private $ignore =
	[
		'./sftp-upload-helper.php',
		'./disabled.htaccess'
	];

	/**
	 * Setter for the ignore property
	 *
	 * @param  array $value
	 * @return SftpSyncTask
	 */
	public function ignore($value)
	{
		$this->ignore = array_merge($this->ignore, $value);
		return $this;
	}

	/**
	 * Executes the SftpSync Task.
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
		// Tell the world whats happening
		$this->printTaskInfo
		(
			'Logging into server - '.
			'<info>'.
				'sftp://'.
				$this->sftpUser.
				':'.
				(empty($this->sftpPass) ? $this->sftpKey : $this->sftpPass).
				'@'.
				$this->sftpHost.
			'</info>'
		);

		// Intialise our sftp connection
		$sftp = new SFTP($this->sftpHost);

		// Do we use password or a key
		if (file_exists($this->sftpKey) && empty($this->sshPass))
		{
			$key = new RSA();
			$key->loadKey(file_get_contents($this->sshKey));
			if (!$sftp->login($this->sshUser, $key))
			{
				return Result::error
				(
					$this,
					'Failed to login via SFTP using Key Based Auth.'
				);
			}
		}
		else
		{
			if (!$sftp->login($this->sftpUser, $this->sftpPass))
			{
				return Result::error
				(
					$this,
					'Failed to login via SFTP using Password Based Auth.'
				);
			}
		}

		// Check to see if a .htaccess file exists
		if ($sftp->stat($this->remotePath.'/.htaccess'))
		{
			// It does so lets rename it, just in case it messes with out helper script
			$this->printTaskInfo('Renaming .htaccess file');
			if (!$sftp->rename($this->remotePath.'/.htaccess', $this->remotePath.'/disabled.htaccess'))
			{
				return Result::error($this, 'Failed to rename .htaccess file');
			}
		}

		// Upload helper script
		$this->printTaskInfo('Uploading sftp helper script.');
		if (!$sftp->put($this->remotePath.'/sftp-upload-helper.php', $this->sftp_upload_helper()))
		{
			return Result::error($this, 'UPLOAD OF HELPER SCRIPT FAILED');
		}

		// Get the local and remote file arrays
		$this->printTaskInfo('Get a list of files on the local and remote servers.');
		$local_files = $this->get_local_file_hashes($this->localPath);
		$remote_files = $this->get_remote_files();

		// Delete helper script
		$this->printTaskInfo('Deleting sftp helper script.');
		if (!$sftp->delete($this->remotePath.'/sftp-upload-helper.php'))
		{
			return Result::error($this, 'FAILED TO DELETE HELPER SCRIPT');
		}

		// Rename htaccess file back
		if ($sftp->stat($this->remotePath.'/disabled.htaccess'))
		{
			// It does so lets rename it, just in case it messes with out helper script
			$this->printTaskInfo('Renaming .htaccess file back to original');
			if (!$sftp->rename($this->remotePath.'/disabled.htaccess', $this->remotePath.'/.htaccess'))
			{
				return Result::error($this, 'Failed to rename .htaccess file back to original. OH SNAP... better fix this ASAP!');
			}
		}

		$this->printTaskInfo('Comparing files between local and remote servers.');

		// Create some arrays
		$files_to_ignore = [];
		$files_to_upload = [];
		$files_to_delete = [];
		$folders_to_create = [];
		$folders_to_delete = [];

		// Merge in our own ignores
		$files_to_ignore = array_merge($files_to_ignore, $this->ignore);

		// Remove any double ups in our ignore array
		$files_to_ignore = array_unique($files_to_ignore);

		// Remove a few extra items
		foreach ($files_to_ignore as $key => $value)
		{
			// We don't want to ignore the vendor dir
			if ($value == './vendor')
			{
				unset($files_to_ignore[$key]);
			}

			// We can't ignore everything
			if ($value == './')
			{
				unset($files_to_ignore[$key]);
			}
		}

		// Loop through the local files array looking for files that
		// don't exist or are different on the remote server.
		// ie: Files to upload
		foreach ($local_files as $path => $hash)
		{
			if (isset($remote_files[$path]))
			{
				if ($hash != $remote_files[$path])
				{
					if (!in_array($path, $files_to_ignore))
					{
						$files_to_upload[] = $path;
					}
				}
			}
			else
			{
				if (!in_array($path, $files_to_ignore))
				{
					if ($hash == 'dir')
					{
						$folders_to_create[] = $path;
					}
					else
					{
						$files_to_upload[] = $path;
					}
				}
			}
		}

		// Loop through the remote files array looking for
		// files that don't exist on the local server.
		// ie: Files to delete
		foreach ($remote_files as $path => $hash)
		{
			if (!isset($local_files[$path]))
			{
				if (!in_array($path, $files_to_ignore))
				{
					if ($hash == 'dir')
					{
						$folders_to_delete[] = $path;
					}
					else
					{
						$files_to_delete[] = $path;
					}
				}
			}
		}

		// We need to delete the children first
		$folders_to_delete = array_reverse($folders_to_delete);

		// Perform a double check of our files to ignore array
		foreach($files_to_ignore as $path)
		{
			foreach ($files_to_upload as $key => $file)
			{
				if (strpos($file, $path) !== false)
				{
					unset($files_to_upload[$key]);
				}
			}

			foreach ($files_to_delete as $key => $file)
			{
				if (strpos($file, $path) !== false)
				{
					unset($files_to_delete[$key]);
				}
			}

			foreach ($folders_to_create as $key => $file)
			{
				if (strpos($file, $path) !== false)
				{
					unset($folders_to_create[$key]);
				}
			}

			foreach ($folders_to_delete as $key => $file)
			{
				if (strpos($file, $path) !== false)
				{
					unset($folders_to_delete[$key]);
				}
			}
		}

		// Check the dry run option
		if (!$this->dryRun)
		{
			// Create any needed folders
			foreach ($folders_to_create as $file)
			{
				$remotepath = str_replace('//', '/', $this->remotePath.substr($file, 1));

				if (!$sftp->mkdir($remotepath))
				{
					return Result::error($this, 'FAILED TO CREATE FOLDER: '.$remotepath);
				}

				$this->printTaskInfo('Folder Created: '.$file);
			}

			// Upload our files
			foreach ($files_to_upload as $file)
			{
				$this->printTaskInfo('Uploading: '.$file);

				$localpath = str_replace('//', '/', $this->localPath.substr($file, 1));

				$remotepath = str_replace('//', '/', $this->remotePath.substr($file, 1));

				if (!$sftp->put($remotepath, $localpath, NET_SFTP_LOCAL_FILE))
				{
					return Result::error($this, 'FAILED TO UPLOAD FILE: '.$file);
				}
			}

			// Do we want to delete all the files?
			$delete_all = false;

			if (count($files_to_delete) > 0)
			{
				print_r($files_to_delete);

				do
				{
					$answer = $this->ask('Do you want to delete all these files? (yes|no)');
				}
				while ($answer != 'yes' && $answer != 'no' && $answer != 'y' && $answer != 'n');

				if ($answer == 'yes' || $answer == 'y')
				{
					$delete_all = true;
				}
			}

			// Loop through our files to delete.
			foreach ($files_to_delete as $file)
			{
				$remotepath = str_replace('//', '/', $this->remotePath.substr($file, 1));

				if ($delete_all)
				{
					if (!$sftp->delete($remotepath))
					{
						return Result::error($this, 'FAILED TO DELETE FILE: '.$file);
					}
					else
					{
						$this->printTaskInfo('Deleted: '.$file);
					}
				}
				else
				{
					do
					{
						$answer = $this->ask('Do you really want to delete? (yes|no)'.$remotepath);
					}
					while ($answer != 'yes' && $answer != 'no' && $answer != 'y' && $answer != 'n');

					if ($answer == 'yes' || $answer == 'y')
					{
						if (!$sftp->delete($remotepath))
						{
							return Result::error($this, 'FAILED TO DELETE FILE: '.$file);
						}

						$this->printTaskInfo('Deleted: '.$file);
					}
				}
			}

			// Same again but for folders
			$delete_all_folders = false;

			if (count($folders_to_delete) > 0)
			{
				print_r($folders_to_delete);

				do
				{
					$answer = $this->ask('Do you want to delete all these folders? (yes|no)');
				}
				while ($answer != 'yes' && $answer != 'no' && $answer != 'y' && $answer != 'n');

				if ($answer == 'yes' || $answer == 'y')
				{
					$delete_all_folders = true;
				}
			}

			foreach ($folders_to_delete as $file)
			{
				$remotepath = str_replace('//', '/', $this->remotePath.substr($file, 1));

				if ($delete_all_folders)
				{
					if (!$sftp->rmdir($remotepath))
					{
						return Result::error($this, 'FAILED TO DELETE FOLDER: '.$file);
					}

					$this->printTaskInfo('Deleted Folder: '.$file);
				}
				else
				{
					do
					{
						$answer = $this->ask('Do you really want to delete? (yes|no)'.$remotepath);
					}
					while ($answer != 'yes' && $answer != 'no' && $answer != 'y' && $answer != 'n');

					if ($answer == 'yes' || $answer == 'y')
					{
						if (!$sftp->rmdir($remotepath))
						{
							return Result::error($this, 'FAILED TO DELETE FOLDER: '.$file);
						}

						$this->printTaskInfo('Deleted Folder: '.$file);
					}
				}
			}

			$this->printTaskInfo('The remote server has been synced :)');
		}
		else
		{
			$this->printTaskInfo('Files that would have been uploaded: ');
			print_r($files_to_upload);

			$this->printTaskInfo('Files that would have been deleted: ');
			print_r($files_to_delete);

			$this->printTaskInfo('Folders that would have been created: ');
			print_r($folders_to_create);

			$this->printTaskInfo('Folders that would have been deleted: ');
			print_r($folders_to_delete);
		}

		// If we get to here we assume everything worked
		return Result::success($this);
	}

	/**
	 * Method: sftp_upload_helper
	 * =========================================================================
	 * This simply creates the contents for our helper script
	 * that we upload to the remote server.
	 *
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * n/a
	 *
	 * Returns:
	 * -------------------------------------------------------------------------
	 * string
	 */
	private function sftp_upload_helper()
	{
		$this->generate_sftp_helper_token();

		return
			'<?php'."\n".
			"\n".
			'if (@$_GET["token"] == "'.$this->token.'")'."\n".
			'{'."\n".
			"\t".'ini_set("memory_limit", "256M");'."\n".
			"\t"."\n".
			"\t".'$files = [];'."\n".
			"\t"."\n".
			"\t".'foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("./", FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $name => $object)'."\n".
			"\t".'{'."\n".
			"\t\t".'if (strpos($name, ".git") === false)'."\n".
			"\t\t".'{'."\n".
			"\t\t\t".'if (is_dir($name))'."\n".
			"\t\t\t".'{'."\n".
			"\t\t\t\t".'$files[$name] = "dir";'."\n".
			"\t\t\t".'}'."\n".
			"\t\t\t".'elseif (file_exists($name))'."\n".
			"\t\t\t".'{'."\n".
			"\t\t\t\t".'$files[$name] = md5(preg_replace("/\r\n?/", "\n", file_get_contents($name)));'."\n".
			"\t\t\t".'}'."\n".
			"\t\t".'}'."\n".
			"\t".'}'."\n".
			"\t"."\n".
			"\t".'header("Content-type: application/json");'."\n".
			"\t".'echo json_encode($files);'."\n".
			'}'."\n"
		;
	}

	/**
	 * Method: generate_sftp_helper_token
	 * =========================================================================
	 * This generates a random string basically.
	 * The token is set to $this->token
	 *
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * n/a
	 *
	 * Returns:
	 * -------------------------------------------------------------------------
	 * void
	 */
	private function generate_sftp_helper_token()
	{
		$this->token = md5(uniqid(rand(), true));
	}

	/**
	 * Method: get_local_file_hashes
	 * =========================================================================
	 * This is the local equivalent of the helper script we upload.
	 *
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * $path - The path to start in
	 *
	 * Returns:
	 * -------------------------------------------------------------------------
	 * array
	 */
	private function get_local_file_hashes($path)
	{
		$files = [];

		foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $name => $object)
		{
			if (strpos($name, ".git") === false)
			{
				if (is_dir($name))
				{
					$files[str_replace($path, '.', $name)] = 'dir';
				}
				elseif(file_exists($name))
				{
					$files[str_replace($path, '.', $name)] = md5(preg_replace('~\r\n?~', "\n", file_get_contents($name)));
				}
			}
		}

		return $files;
	}

	/**
	 * Method: get_remote_files
	 * =========================================================================
	 * This uses giuzzle to call the sftp helper script.
	 *
	 * Parameters:
	 * -------------------------------------------------------------------------
	 * n/a
	 *
	 * Returns:
	 * -------------------------------------------------------------------------
	 * array
	 */
	private function get_remote_files()
	{
		$client = new \GuzzleHttp\Client(['base_uri' => 'http://'.$this->httpHost]);

		$results = json_decode($client->get('/sftp-upload-helper.php', ['query' => ['token' => $this->token]])->getBody());

		$new = [];
		foreach ($results as $key => $value)
		{
			if ($key == './disabled.htaccess')
			{
				$new['./.htaccess'] = $value;
			}
			else
			{
				$new[$key] = $value;
			}
		}

		return $new;
	}
}
