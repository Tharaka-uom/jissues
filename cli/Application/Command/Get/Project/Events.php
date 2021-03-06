<?php
/**
 * Part of the Joomla! Tracker application.
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace Application\Command\Get\Project;

use App\Tracker\Table\ActivitiesTable;

use Application\Command\Get\Project;
use Application\Command\TrackerCommandOption;

use Joomla\Date\Date;

/**
 * Class for retrieving events from GitHub for selected projects
 *
 * @since  1.0
 */
class Events extends Project
{
	/**
	 * The command "description" used for help texts.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $description = 'Retrieve issue events from GitHub.';

	/**
	 * Event data from GitHub
	 *
	 * @var    array
	 * @since  1.0
	 */
	protected $items = array();

	/**
	 * Constructor.
	 *
	 * @since   1.0
	 */
	public function __construct()
	{
		parent::__construct();

		$this->addOption(
			new TrackerCommandOption(
				'issue', '',
				'<n> Process only a single issue.'
			)
		)->addOption(
			new TrackerCommandOption(
				'all', '',
				'Process all issues.'
			)
		);
	}

	/**
	 * Execute the command.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function execute()
	{
		$this->getApplication()->outputTitle('Retrieve Events');

		$this->logOut('Start retrieve Events')
			->selectProject()
			->setupGitHub()
			->fetchData()
			->processData()
			->out()
			->logOut('Finished');
	}

	/**
	 * Set the changed issues.
	 *
	 * @param   array  $changedIssueNumbers  List of changed issue numbers.
	 *
	 * @return $this
	 *
	 * @since   1.0
	 */
	public function setChangedIssueNumbers(array $changedIssueNumbers)
	{
		$this->changedIssueNumbers = $changedIssueNumbers;

		return $this;
	}

	/**
	 * Method to get the comments on items from GitHub
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 */
	protected function fetchData()
	{
		if (!$this->changedIssueNumbers)
		{
			return $this;
		}

		$this->out(sprintf('Fetch events for <b>%d</b> issue(s) from GitHub...', count($this->changedIssueNumbers)), false);

		$progressBar = $this->getProgressBar(count($this->changedIssueNumbers));

		$this->usePBar ? $this->out() : null;

		foreach ($this->changedIssueNumbers as $count => $issueNumber)
		{
			$this->usePBar
				? $progressBar->update($count + 1)
				: $this->out(
					sprintf(
						'%d/%d - # %d: ', $count + 1, count($this->changedIssueNumbers), $issueNumber
					),
					false
				);

			$page = 0;
			$this->items[$issueNumber] = array();

			do
			{
				$page++;

				$events = $this->github->issues->events->getList(
					$this->project->gh_user, $this->project->gh_project, $issueNumber, $page, 100
				);

				$this->checkGitHubRateLimit($this->github->issues->events->getRateLimitRemaining());

				$count = is_array($events) ? count($events) : 0;

				if ($count)
				{
					$this->items[$issueNumber] = array_merge($this->items[$issueNumber], $events);

						$this->usePBar
							? null
							: $this->out($count . ' ', false);
				}
			}

			while ($count);
		}

		// Retrieved items, report status
		$this->out()
			->outOK();

		return $this;
	}

	/**
	 * Method to process the list of issues and inject into the database as needed
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	protected function processData()
	{
		if (!$this->items)
		{
			$this->logOut('Everything is up to date.');

			return $this;
		}

		/* @type \Joomla\Database\DatabaseDriver $db */
		$db = $this->getContainer()->get('db');

		$query = $db->getQuery(true);

		$this->out('Adding events to the database...', false);

		$progressBar = $this->getProgressBar(count($this->items));

		$this->usePBar ? $this->out() : null;

		$adds = 0;
		$count = 0;

		// Initialize our ActivitiesTable instance to insert the new record
		$table = new ActivitiesTable($db);

		foreach ($this->items as $issueId => $events)
		{
			$this->usePBar
				? null
				: $this->out(sprintf(' #%d (%d/%d)...', $issueId, $count + 1, count($this->items)), false);

			foreach ($events as $event)
			{
				switch ($event->event)
				{
					case 'referenced' :
					case 'closed' :
					case 'reopened' :
					case 'assigned' :
					case 'merged' :
					case 'head_ref_deleted' :
					case 'head_ref_restored' :
						$query->clear()
							->select($table->getKeyName())
							->from($db->quoteName('#__activities'))
							->where($db->quoteName('gh_comment_id') . ' = ' . (int) $event->id)
							->where($db->quoteName('project_id') . ' = ' . (int) $this->project->project_id);

						$db->setQuery($query);

						$id = (int) $db->loadResult();

						$table->reset();
						$table->{$table->getKeyName()} = null;

						if ($id && !$this->force)
						{
							if ($this->force)
							{
								// Force update
								$this->usePBar ? null : $this->out('F', false);

								$table->{$table->getKeyName()} = $id;
							}
							else
							{
								// If we have something already, then move on to the next item
								$this->usePBar ? null : $this->out('-', false);

								continue;
							}
						}
						else
						{
							$this->usePBar ? null : $this->out('+', false);
						}

						// Translate GitHub event names to "our" name schema
						$evTrans = array(
							'referenced' => 'reference', 'closed' => 'close', 'reopened' => 'reopen',
							'assigned' => 'assign', 'merged' => 'merge', 'head_ref_deleted' => 'head_ref_deleted',
							'head_ref_restored' => 'head_ref_restored'
						);

						$table->gh_comment_id = $event->id;
						$table->issue_number  = $issueId;
						$table->project_id    = $this->project->project_id;
						$table->user          = $event->actor->login;
						$table->event         = $evTrans[$event->event];

						$table->created_date = (new Date($event->created_at))->format('Y-m-d H:i:s');

						if ('referenced' == $event->event)
						{
							// @todo obtain referenced information

							/*
							$reference = $this->github->issues->events->get(
								$this->project->gh_user, $this->project->gh_project, $event->id
							);

							$this->checkGitHubRateLimit($this->github->issues->events->getRateLimitRemaining());
							*/
						}

						if ('assigned' == $event->event)
						{
							$reference = $this->github->issues->events->get(
								$this->project->gh_user, $this->project->gh_project, $event->id
							);

							$table->text_raw = 'Assigned to ' . $reference->issue->assignee->login;
							$table->text = $table->text_raw;

							$this->checkGitHubRateLimit($this->github->issues->events->getRateLimitRemaining());
						}

						$table->store();

						++ $adds;
						break;

					case 'mentioned' :
					case 'subscribed' :
					case 'unsubscribed' :
						continue;
						break;

					default:
						throw new \UnexpectedValueException('Unknown event: ' . $event->event);
						continue;
						break;
				}
			}

			++ $count;

			$this->usePBar
				? $progressBar->update($count)
				: null;
		}

		$this->out()
			->outOK()
			->logOut(sprintf('Added %d new issue events to the database', $adds));

		return $this;
	}
}
