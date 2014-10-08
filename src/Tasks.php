<?php namespace Brads\Robo;

class Tasks extends \Robo\Tasks
{
	use Task\CreateDb;
	use Task\ExecuteSqlViaPhpMyAdmin;
	use Task\ImportSqlDump;
	use Task\PullDbViaPhpMyAdmin;
	use Task\PushDbViaPhpMyAdmin;
	use Task\PullDbViaSsh;
	use Task\PushDbViaSsh;
	use Task\SftpSync;
	use Task\SearchReplaceDb;
	use Task\WordpressSandbox;
}