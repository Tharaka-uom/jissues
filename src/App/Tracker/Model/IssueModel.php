<?php
/**
 * Part of the Joomla Tracker's Tracker Application
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace App\Tracker\Model;

use App\Tracker\Table\ActivitiesTable;
use App\Tracker\Table\IssuesTable;

use Joomla\Filter\InputFilter;

use JTracker\Model\AbstractTrackerDatabaseModel;

/**
 * Model to get data for the issue list view
 *
 * @since  1.0
 */
class IssueModel extends AbstractTrackerDatabaseModel
{
	/**
	 * Context string for the model type.  This is used to handle uniqueness
	 * when dealing with the getStoreId() method and caching data structures.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $context = 'com_tracker.issue';

	/**
	 * Get an item.
	 *
	 * @param   integer  $identifier  The item identifier.
	 *
	 * @return  IssuesTable
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function getItem($identifier = null)
	{
		if (!$identifier)
		{
			throw new \RuntimeException('No id given');
		}

		$item = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('i.*')
				->from($this->db->quoteName('#__issues', 'i'))
				->where($this->db->quoteName('i.project_id') . ' = ' . (int) $this->getProject()->project_id)
				->where($this->db->quoteName('i.issue_number') . ' = ' . (int) $identifier)

				// Join over the status table
				->select($this->db->quoteName('s.status', 'status_title'))
				->select($this->db->quoteName('s.closed', 'closed'))
				->leftJoin(
					$this->db->quoteName('#__status', 's')
					. ' ON '
					. $this->db->quoteName('i.status')
					. ' = ' . $this->db->quoteName('s.id')
				)

				// Get the relation information
				->select('a1.title AS rel_title, a1.status AS rel_status')
				->join('LEFT', '#__issues AS a1 ON i.rel_number = a1.issue_number')

				// Join over the status table
				->select('s1.closed AS rel_closed')
				->join('LEFT', '#__status AS s1 ON a1.status = s1.id')

				// Join over the relations_types table
				->select('t.name AS rel_name')
				->join('LEFT', '#__issues_relations_types AS t ON i.rel_type = t.id')
		)->loadObject();

		if (!$item)
		{
			throw new \RuntimeException('Invalid Issue', 1);
		}

		// Fetch activities
		$table = new ActivitiesTable($this->db);
		$query = $this->db->getQuery(true);

		$query->select('a.*');
		$query->from($this->db->quoteName($table->getTableName(), 'a'));
		$query->where($this->db->quoteName('a.project_id') . ' = ' . (int) $this->getProject()->project_id);
		$query->where($this->db->quoteName('a.issue_number') . ' = ' . (int) $item->issue_number);
		$query->order($this->db->quoteName('a.created_date'));

		$item->activities = $this->db->setQuery($query)->loadObjectList();

		// Fetch foreign relations
		$item->relations_f = $this->db->setQuery(
			$this->db->getQuery(true)
				->from($this->db->quoteName('#__issues', 'a'))
				->join('LEFT', '#__issues_relations_types AS t ON a.rel_type = t.id')
				->join('LEFT', '#__status AS s ON a.status = s.id')
				->select('a.issue_number, a.title, a.rel_type')
				->select('t.name AS rel_name')
				->select('s.status AS status_title, s.closed AS closed')
				->where($this->db->quoteName('a.rel_number') . '=' . (int) $item->issue_number)
				->order(array('a.issue_number', 'a.rel_type'))
		)->loadObjectList();

		// Group relations by type
		if ($item->relations_f)
		{
			$arr = array();

			foreach ($item->relations_f as $relation)
			{
				if (false == isset($arr[$relation->rel_name]))
				{
					$arr[$relation->rel_name] = array();
				}

				$arr[$relation->rel_name][] = $relation;
			}

			$item->relations_f = $arr;
		}

		// Fetch the voting data
		$query->clear()
			->select('COUNT(id) AS votes, SUM(experienced) AS experienced, SUM(score) AS score')
			->from($this->db->quoteName('#__issues_voting'))
			->where($this->db->quoteName('issue_number') . ' = ' . (int) $item->id);
		$voteData = $this->db->setQuery($query)->loadObject();

		$item->votes       = $voteData->votes;
		$item->experienced = $voteData->experienced;
		$item->score       = $voteData->score;

		// Set the score if we have votes
		if ($item->votes > 0)
		{
			$item->importanceScore = $item->score / $item->votes;
		}
		else
		{
			$item->importanceScore = 0;
		}

		return $item;
	}

	/**
	 * Get a random issue number.
	 *
	 * @return  integer A random issue number.
	 *
	 * @since   1.0
	 * @throws \RuntimeException
	 */
	public function getRandomNumber()
	{
		$issueNumber = $this->db->setQuery(
				$this->db->getQuery(true)
					->select('i.issue_number')
					->from($this->db->quoteName('#__issues', 'i'))
					->join('LEFT', '#__activities AS a ON a.issue_number = i.issue_number')
					->join('LEFT', '#__status AS s on s.id = i.status')
					->where($this->db->quoteName('i.project_id') . ' = ' . (int) $this->getProject()->project_id)
					->where($this->db->quoteName('s.closed') . '=' . 0)
					->where($this->db->quoteName('a.event') . '=' . $this->db->quote('comment'))
					->group('i.id')
					->having('COUNT(a.activities_id) < 5')
					->order('RAND()'), 0, 1
		)->loadResult();

		if (!$issueNumber)
		{
			throw new \RunTimeException('No issues with less than 5 comments');
		}

		return $issueNumber;
	}

	/**
	 * Get a status list.
	 *
	 * @return  array
	 *
	 * @since   1.0
	 */
	public function getStatuses()
	{
		return $this->db->setQuery(
			$this->db->getQuery(true)
				->from($this->db->quoteName('#__status'))
				->select('*')
		)->loadObjectList();
	}

	/**
	 * Add the item.
	 *
	 * @param   array  $src  The source.
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 */
	public function add(array $src)
	{
		$filter = new InputFilter;

		$src['description_raw'] = $filter->clean($src['description_raw'], 'string');

		// Store the issue
		$table = new IssuesTable($this->db);

		$table->save($src);

		/*
		@todo see issue #194
		Store the activity
		$table = new ActivitiesTable($this->db);

		$src['event']   = 'open';
		$src['user']    = $src['opened_by'];

		$table->save($src);*/

		return $this;
	}

	/**
	 * Save the item.
	 *
	 * @param   array  $src  The source.
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function save(array $src)
	{
		$filter = new InputFilter;

		$data = array();

		$data['id']              = $filter->clean($src['id'], 'int');
		$data['status']          = $filter->clean($src['status'], 'int');
		$data['priority']        = $filter->clean($src['priority'], 'int');
		$data['title']           = $filter->clean($src['title'], 'string');
		$data['build']           = $filter->clean($src['build'], 'string');
		$data['description_raw'] = $filter->clean($src['description_raw'], 'string');
		$data['rel_number']      = $filter->clean($src['rel_number'], 'int');
		$data['rel_type']        = $filter->clean($src['rel_type'], 'int');
		$data['easy']            = $filter->clean($src['easy'], 'int');
		$data['tests']           = $filter->clean($src['tests'], 'int');

		if (!$data['id'])
		{
			throw new \RuntimeException('Missing ID');
		}

		$table = new IssuesTable($this->db);

		$table->load($data['id'])
			->save($data);

		return $this;
	}

	/**
	 * Update vote data for an issue
	 *
	 * @param   integer  $id           The issue ID
	 * @param   integer  $experienced  Whether the user has experienced the issue
	 * @param   integer  $importance   The importance of the issue to the user
	 * @param   integer  $userID       The user ID of the user submitting the vote
	 *
	 * @return  object
	 *
	 * @since   1.0
	 */
	public function vote($id, $experienced, $importance, $userID)
	{
		$db    = $this->getDb();
		$query = $db->getQuery(true);

		// Check if a vote exists for the user already
		$query->select('id')
			->from($db->quoteName('#__issues_voting'))
			->where($db->quoteName('user_id') . ' = ' . $userID)
			->where($db->quoteName('issue_number') . ' = ' . $id);

		$voteId = $db->setQuery($query)->loadResult();

		// Insert a new record if one doesn't exist
		if (!$voteId)
		{
			$columnsArray = array(
				$db->quoteName('issue_number'),
				$db->quoteName('user_id'),
				$db->quoteName('experienced'),
				$db->quoteName('score'),
			);

			$query->clear()
				->insert($db->quoteName('#__issues_voting'))
				->columns($columnsArray)
				->values(
					$id . ', '
					. $userID . ', '
					. $experienced . ', '
					. $importance
				);
		}
		else
		{
			$query->clear()
				->update($db->quoteName('#__issues_voting'))
				->set($db->quoteName('experienced') . ' = ' . $experienced)
				->set($db->quoteName('score') . ' = ' . $importance)
				->where($db->quoteName('id') . ' = ' . (int) $voteId);
		}

		$db->setQuery($query)->execute();

		$insertId = $db->insertid();

		// Get the updated vote data to update the display
		if (!$voteId)
		{
			$voteId = $insertId;
		}

		$query->clear()
			->select('SUM(score) AS score, COUNT(id) AS votes')
			->from($db->quoteName('#__issues_voting'))
			->where($db->quoteName('issue_number') . ' = ' . (int) $id);

		return $db->setQuery($query)->loadObject();
	}
}
