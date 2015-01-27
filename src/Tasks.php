<?php namespace Brads\Robo;

class Tasks extends \Robo\Tasks
{
	use \Gears\Asset;
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
	
	protected function askYesNo($question)
	{
		$answer = $this->askWithForcedAnswers($question, ['yes', 'no']);

		if ($answer == 'yes')
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	protected function askWithForcedAnswers($question, $answers)
	{
		// Ask the question
		do $answer = $this->ask($question.' ('.implode('|', $answers).')');

		// Continue to ask the question until we get a valid answer
		while (!in_array($answer, $answers));

		// Return the answer
		return $answer;
	}
}