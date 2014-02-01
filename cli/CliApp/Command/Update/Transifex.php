<?php
/**
 * Part of the Joomla! Tracker application.
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace CliApp\Command\Update;

use g11n\Language\Storage;
use g11n\Support\ExtensionHelper;

use Joomla\Filesystem\Folder;
use Joomla\Filter\OutputFilter;

/**
 * Class for updating resources on Transifex
 *
 * @since  1.0
 */
class Transifex extends Update
{
	/**
	 * The command "description" used for help texts.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $description = 'Update language files on Transifex.';

	/**
	 * Execute the command.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function execute()
	{
		$this->getApplication()->outputTitle('Update Translations');

		$this->logOut('Start pushing translations.')
			->setupTransifex()
			->uploadTemplates()
			->out()
			->logOut('Finished.');
	}

	/**
	 * Push translations.
	 *
	 * @return  $this
	 *
	 * @throws \DomainException
	 * @since   1.0
	 */
	private function uploadTemplates()
	{
		$transifexProject = $this->getApplication()->get('transifex.project');
		$create = $this->getApplication()->input->get('create');

		defined('JDEBUG') || define('JDEBUG', 0);

		ExtensionHelper::addDomainPath('Core', JPATH_ROOT . '/src');
		ExtensionHelper::addDomainPath('Template', JPATH_ROOT . '/templates');
		ExtensionHelper::addDomainPath('App', JPATH_ROOT . '/src/App');

		$scopes = array(
			'Core' => array(
				'JTracker'
			),
			'Template' => array(
				'JTracker'
			),
			'App' => Folder::folders(JPATH_ROOT . '/src/App')
		);

		foreach ($scopes as $domain => $extensions)
		{
			foreach ($extensions as $extension)
			{
				$name  = $extension . ' ' . $domain;
				$alias = OutputFilter::stringURLSafe($name);

				$this->out('Processing: ' . $name . ' - ' . $alias);

				$templatePath = Storage::getTemplatePath($extension, $domain);

				if (false == file_exists($templatePath))
				{
					throw new \DomainException(sprintf('Language template for %s not found.', $name));
				}

				$this->out($templatePath);

				try
				{
					if ($create)
					{
						$this->transifex->resources->createResource(
							$transifexProject, $name, $alias, 'PO', array('file' => $templatePath)
						);

						$this->out('<ok>Resource created successfully</ok>');
					}
					else
					{
						$this->transifex->resources->updateResourceContent(
							$transifexProject, $alias, $templatePath, 'file'
						);

						$this->out('<ok>Resource updated successfully</ok>');
					}
				}
				catch (\Exception $e)
				{
					$this->out('<error>' . $e->getMessage() . '</error>');
				}

				$this->out();
			}
		}

		return $this;
	}
}
