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

	/**
	 * Asks a simple Yes or No Question.
	 *
	 * @param  string  $question The question to ask.
	 * @return boolean           true for yes, false for no.
	 */
	protected function askYesNo($question)
	{
		return $this->confirm($question) === 'y' ? true : false;
	}

	/**
	 * Asks a question that must provide an answer from a specfic set of answers
	 *
	 * @param  string $question The question to ask.
	 * @param  array  $answers  A set of possible answers.
	 * @return string           The provided answer.
	 */
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
