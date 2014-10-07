Brads Additional Robo Tasks
================================================================================
[![Build Status](https://travis-ci.org/brad-jones/robo-tasks.svg)](https://travis-ci.org/brad-jones/robo-tasks)
[![Latest Stable Version](https://poser.pugx.org/brad-jones/robo-tasks/v/stable.svg)](https://packagist.org/packages/brad-jones/robo-tasks)
[![Total Downloads](https://poser.pugx.org/brad-jones/robo-tasks/downloads.svg)](https://packagist.org/packages/brad-jones/robo-tasks)
[![License](https://poser.pugx.org/brad-jones/robo-tasks/license.svg)](https://packagist.org/packages/brad-jones/robo-tasks)

These are some tasks I have collated from various projects and generalised so I
don't have to re invent the wheel for every new project I create.

All tasks are unit tested however there is plenty of room for
improvement here so please use with caution.

If you have not come across the PHP Task Runner called *Robo*,
see: http://robo.li/

How to Use:
--------------------------------------------------------------------------------
First up run the following:

    composer require brad-jones/robo-tasks:*

Assuming you already have *robo* installed, and you have a ```RoboFile.php```.

**Method 1:** Extend my tasks class like so.

```php
<?php

/*
 * NOTE: I wouldn't normally install robo globally. I use composer to install
 * it for me. However in some cases people still run a global version of robo.
 * Thus we require our local composer autoloader just in case.
 */
require_once(__DIR__.'/vendor/autoload.php');

class RoboFile extends Brads\Robo\Tasks
{
	public function someCommand()
	{
		// now my tasks are available
		$this->taskCreateDb()
			->host('127.0.0.1')
			->user('root')
			->pass('')
			->name('myapp_test')
		->run();
	}
}
```

**Method 2:** Import my tasks as needed, like so.

```php
<?php

require_once(__DIR__.'/vendor/autoload.php');

class RoboFile extends Robo\Tasks
{
	// import additional task
	use Brads\Task\CreateDb;

	public function someCommand()
	{
		$this->taskCreateDb()
			->host('127.0.0.1')
			->user('root')
			->pass('')
			->name('myapp_test')
		->run();
	}
}
```

--------------------------------------------------------------------------------
Developed by Brad Jones - brad@bjc.id.au