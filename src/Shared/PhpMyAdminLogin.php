<?php namespace Brads\Robo\Shared;

use RuntimeException;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\Base\loadTasks;
use Robo\Common\DynamicParams;
use GuzzleHttp\Client as Http;
use GuzzleHttp\TransferStats;
use function Stringy\create as s;

trait PhpMyAdminLogin
{
	protected function taskPhpMyAdminLogin()
	{
		return new PhpMyAdminLoginTask();
	}
}

class PhpMyAdminLoginTask extends BaseTask
{
    use loadTasks, DynamicParams;

    /** @var string */
	private $phpMyAdminUrl;

	/** @var string */
	private $phpMyAdminUser;

	/** @var string */
	private $phpMyAdminPass;

	/** @var string */
	private $remoteDbHost;

    /** @var GuzzleHttp\Client */
    private $http;

    /**
     * Get the guzzel http client after it has been logged in.
     *
     * @return GuzzleHttp\Client
     */
    public function getClient()
    {
        return $this->http;
    }

    /** @var string */
    private $token;

    /**
     * Even though a cookie is used for authentication
     * you still need to submit the token in all requests.
     * Thus this is where you get it from after logging in.
     *
     * > TODO: Setup some middleware on the Guzzle client
     * > to automatically inject the token...
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /** @var string */
    private $serverId;

    /**
     * If logging into the specfic server.
     * Then you will need this for future requests.
     * Avaliable aqfter successfully logging in.
     *
     * @return string
     */
    public function getServerId()
    {
        return $this->serverId;
    }

    /**
	 * Executes the PhpMyAdminLoginTask Task.
	 *
	 * Example usage:
	 * ```php
	 * 	$loggedInHttpClient = $this->taskPhpMyAdminLogin()
	 * 		->phpMyAdminUrl('...')
	 * 		->phpMyAdminUrl('...')
	 * 		->phpMyAdminUrl('...')
	 * 		->phpMyAdminUrl('...')
	 * 		->phpMyAdminUrl('...')
	 * 	->run()->getTask()->getClient();
	 * ```
	 *
	 * @return Robo\Result
	 */
	public function run()
	{
        // Setup guzzle client
		$this->http = new Http
		([
			'base_uri' => $this->phpMyAdminUrl,
			'cookies' => true,
			'verify' => false
		]);

		// Tell the world whats happening
		$this->printTaskInfo
		(
			'Logging into phpmyadmin - <info>'.
			str_replace
			(
				'://',
				'://'.$this->phpMyAdminUser.':'.$this->phpMyAdminPass.'@',
				$this->phpMyAdminUrl
			).'</info>'
		);

		// Make an intial request so we can extract some info
		$html = $this->http->get('/')->getBody();

		// Grab the token
		$this->token = $this->extractToken($html);

		// Get the server id
		$this->serverId = $this->extractServerId($html);

		// Login, we are assuming cookie based auth.
		// One day I'll get around to supporting the other types of auth
		// phpMyAdmin supports but right now I have no need...
		$this->http->post('/',
		[
			'form_params' =>
			[
				'pma_username' => $this->phpMyAdminUser,
				'pma_password' => $this->phpMyAdminPass,
				'server' => $this->serverId,
				'token' => $this->token
			],
			'on_stats' => function(TransferStats $stats) use (&$effectiveUri)
			{
				$effectiveUri = $stats->getEffectiveUri();
			}
		]);

		// Check to see if we passed auth
		if (!s($effectiveUri)->contains($this->token))
		{
			throw new RuntimeException('phpMyAdmin Login Failed');
		}

        // If we get to here assume everything worked.
        // The http client can be accessed by calling getTask()->getClient().
		return Result::success($this);
    }

    /**
     * Given some html we will attempt to extract the csrf token from the page.
     *
     * @param  string $html
     * @return null|string
     */
    private function extractToken($html)
    {
        $token = null;

		$regex = '#<input type="hidden" name="token" value="(.*?)" />#s';

		if (preg_match($regex, $html, $matches) === 1)
		{
			$token = $matches[1];
		}

        return $token;
    }

    /**
     * Given some html we will attempt to extract the id of the remote db.
     *
     * @param  string $html
     * @return null|string
     */
    private function extractServerId($html)
    {
        $server_id = null;

		$regex =
            '#<option value="(\d+)".*?>'
                .preg_quote($this->remoteDbHost, '#').
            '.*?</option>#'
        ;

		if (preg_match($regex, $html, $matches) === 1)
		{
			$server_id = $matches[1];
		}

        return $server_id;
    }
}
