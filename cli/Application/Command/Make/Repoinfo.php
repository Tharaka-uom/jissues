<?php
/**
 * Part of the Joomla! Tracker application.
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace Application\Command\Make;

/**
 * Class for generating repository information.
 *
 * @since  1.0
 */
class Repoinfo extends Make
{
	/**
	 * The command "description" used for help texts.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $description = 'Generate repository information.';

	/**
	 * Execute the command.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  \DomainException
	 */
	public function execute()
	{
		$path = JPATH_ROOT . '/current_SHA';

		$this->getApplication()->outputTitle('Generate Repoinfo');
		$this->logOut('Generating Repoinfo.');

		$info   = $this->execCommand('cd ' . JPATH_ROOT . ' && git describe --long --abbrev=10 --tags 2>&1');
		$branch = $this->execCommand('cd ' . JPATH_ROOT . ' && git rev-parse --abbrev-ref HEAD 2>&1');

		if (false == file_put_contents($path, $info . ' ' . $branch))
		{
			$this->logOut(sprintf('Can not write to path: %s', str_replace(JPATH_ROOT, 'J_ROOT', $path)));

			throw new \DomainException('Can not write to path: ' . $path);
		}

		$this->logOut(sprintf('Wrote repoinfo file to: %s', str_replace(JPATH_ROOT, 'J_ROOT', $path)))
			->out()
			->out('Finished =;)');
	}
}
