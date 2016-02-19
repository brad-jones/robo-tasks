<?php namespace Brads\Robo\Shared;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use GuzzleHttp\Client as Http;
use GuzzleHttp\TransferStats;
use Brads\Robo\Shared\PhpMyAdminLogin;
use Brads\Robo\Shared\PhpMyAdminLoginTask;

trait PhpMyAdminListTables
{
	protected function taskPhpMyAdminListTables(PhpMyAdminLoginTask $loggedIn)
	{
		return new PhpMyAdminListTablesTask($loggedIn);
	}
}

class PhpMyAdminListTablesTask extends BaseTask
{
    use loadTasks, DynamicParams;

    /** @var PhpMyAdminLoginTask */
    private $loggedIn;

    /** @var string */
	private $remoteDbName;

    /** @var array */
	private $tables = [];

    /**
     * Simply lists all the tables of a database.
     *
     * @return array
     */
    public function getTables()
    {
        return $this->tables;
    }

    /**
	 * @param PhpMyAdminLoginTask $loggedIn An already logged in task.
	 */
	public function __construct(PhpMyAdminLoginTask $loggedIn)
	{
		$this->loggedIn = $loggedIn;
	}

    /**
	 * Executes the PhpMyAdminListTablesTask Task.
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
        // Grab a list of tables
		$this->printTaskInfo('Getting list of tables.');
		$html = $this->loggedIn->getClient()->get('db_structure.php',
		[
			'query' =>
			[
				'db' => $this->remoteDbName,
				'server' => $this->loggedIn->getServerId(),
				'token' => $this->loggedIn->getToken()
			]
		])->getBody();

        // Extract a list of tables from the html
        $regex = '/<a href="sql\.php\?db=.*?table=(.*?)&amp;pos=0" title="">.*?<\/a>/i';
		if (preg_match_all($regex, $html, $matches) !== false)
        {
            if (isset($matches[1]))
            {
                $this->tables = $matches[1];
            }
        }

        // If we get to here assume everything worked.
        // The tables can be accessed by calling getTask()->getTables().
		return Result::success($this);
    }
}
