<?php
namespace Craft;

/**
 *
 */
class EntriesService extends BaseApplicationComponent
{
	/**
	 * Returns an entry by its ID.
	 *
	 * @param int $entryId
	 * @return EntryModel|null
	 */
	public function getEntryById($entryId)
	{
		if ($entryId)
		{
			$criteria = craft()->elements->getCriteria(ElementType::Entry);
			$criteria->id = $entryId;
			$criteria->status = null;
			return $criteria->first();
		}
	}

	/**
	 * Saves an entry.
	 *
	 * @param EntryModel $entry
	 * @throws Exception
	 * @return bool
	 */
	public function saveEntry(EntryModel $entry)
	{
		$isNewEntry = !$entry->id;

		$hasNewParent = (Craft::hasPackage(CraftPackage::PublishPro) &&
			$entry->getSection()->type == SectionType::Structure &&
			$entry->parentId !== null &&
			($entry->parentId !== '0' || $entry->depth != 1) &&
			(!$entry->getParent() || $entry->getParent()->id != $entry->parentId)
		);

		if ($hasNewParent)
		{
			if ($entry->parentId)
			{
				$parentEntry = $this->getEntryById($entry->parentId);

				if (!$parentEntry)
				{
					throw new Exception(Craft::t('No entry exists with the ID “{id}”', array('id' => $entry->parentId)));
				}
			}
			else
			{
				$parentEntry = null;
			}

			$entry->setParent($parentEntry);

			$entryRecordClass = __NAMESPACE__.'\\StructuredEntryRecord';
		}
		else
		{
			$entryRecordClass = __NAMESPACE__.'\\EntryRecord';
		}

		// Entry data
		if (!$isNewEntry)
		{
			$entryRecord = $entryRecordClass::model()->with('element')->findById($entry->id);

			if (!$entryRecord)
			{
				throw new Exception(Craft::t('No entry exists with the ID “{id}”', array('id' => $entry->id)));
			}

			$elementRecord = $entryRecord->element;

			// if entry->sectionId is null and there is an entryRecord sectionId, we assume this is a front-end edit.
			if ($entry->sectionId === null && $entryRecord->sectionId)
			{
				$entry->sectionId = $entryRecord->sectionId;
			}
		}
		else
		{
			$entryRecord = new $entryRecordClass();

			$elementRecord = new ElementRecord();
			$elementRecord->type = ElementType::Entry;
		}

		$section = craft()->sections->getSectionById($entry->sectionId);

		if (!$section)
		{
			throw new Exception(Craft::t('No section exists with the ID “{id}”', array('id' => $entry->sectionId)));
		}

		$sectionLocales = $section->getLocales();

		if (!isset($sectionLocales[$entry->locale]))
		{
			throw new Exception(Craft::t('The section “{section}” is not enabled for the locale {locale}', array('section' => $section->name, 'locale' => $entry->locale)));
		}

		$entryRecord->sectionId  = $entry->sectionId;
		$entryRecord->postDate   = $entry->postDate;

		if ($section->type == SectionType::Single)
		{
			$entryRecord->authorId   = $entry->authorId = null;
			$entryRecord->expiryDate = $entry->expiryDate = null;

			$elementRecord->enabled  = $entry->enabled = true;
		}
		else
		{
			$entryRecord->authorId   = $entry->authorId;
			$entryRecord->postDate   = $entry->postDate;
			$entryRecord->expiryDate = $entry->expiryDate;

			$elementRecord->enabled  = $entry->enabled;
		}

		if ($entry->enabled && !$entryRecord->postDate)
		{
			// Default the post date to the current date/time
			$entryRecord->postDate = $entry->postDate = DateTimeHelper::currentUTCDateTime();
		}

		$entryRecord->validate();
		$elementRecord->validate();

		$entry->addErrors($entryRecord->getErrors());
		$entry->addErrors($elementRecord->getErrors());

		// Entry locale data
		if ($entry->id)
		{
			$entryLocaleRecord = EntryLocaleRecord::model()->findByAttributes(array(
				'entryId' => $entry->id,
				'locale'  => $entry->locale
			));

			// If entry->slug is null and there is an entryLocaleRecord slug, we assume this is a front-end edit.
			if ($entry->slug === null && $entryLocaleRecord->slug)
			{
				$entry->slug = $entryLocaleRecord->slug;
			}
		}

		if (empty($entryLocaleRecord))
		{
			$entryLocaleRecord = new EntryLocaleRecord();
			$entryLocaleRecord->sectionId = $entry->sectionId;
			$entryLocaleRecord->locale    = $entry->locale;
		}

		if ($entryLocaleRecord->isNewRecord() || $entry->slug != $entryLocaleRecord->slug)
		{
			$this->_generateEntrySlug($entry);
			$entryLocaleRecord->slug = $entry->slug;
		}

		$entryLocaleRecord->validate();
		$entry->addErrors($entryLocaleRecord->getErrors());

		// Element locale data
		if ($entry->id)
		{
			$elementLocaleRecord = ElementLocaleRecord::model()->findByAttributes(array(
				'elementId' => $entry->id,
				'locale'    => $entry->locale
			));
		}

		if (empty($elementLocaleRecord))
		{
			$elementLocaleRecord = new ElementLocaleRecord();
			$elementLocaleRecord->locale = $entry->locale;
		}

		if ($section->type == SectionType::Single)
		{
			$elementLocaleRecord->uri = $sectionLocales[$entry->locale]->urlFormat;
		}
		else if ($section->hasUrls && $entry->enabled)
		{
			if ($section->type == SectionType::Structure && $entry->parentId)
			{
				$urlFormatAttribute = 'nestedUrlFormat';
			}
			else
			{
				$urlFormatAttribute = 'nestedUrl';
			}

			$urlFormat = $sectionLocales[$entry->locale]->$urlFormatAttribute;

			// Make sure the section's URL format is valid. This shouldn't be possible due to section validation,
			// but it's not enforced by the DB, so anything is possible.
			if (!$urlFormat || mb_strpos($urlFormat, '{slug}') === false)
			{
				throw new Exception(Craft::t('The section “{section}” doesn’t have a valid URL Format.', array(
					'section' => Craft::t($section->name)
				)));
			}

			$elementLocaleRecord->uri = craft()->templates->renderObjectTemplate($urlFormat, $entry);
		}
		else
		{
			$elementLocaleRecord->uri = null;
		}

		$elementLocaleRecord->validate();
		$entry->addErrors($elementLocaleRecord->getErrors());

		// Entry content
		$entryType = $entry->getType();

		if (!$entryType)
		{
			throw new Exception(Craft::t('No entry types are available for this entry.'));
		}

		// Set the typeId attribute on the model in case it hasn't been set
		$entry->typeId = $entryRecord->typeId = $entryType->id;

		$fieldLayout = $entryType->getFieldLayout();
		$content = craft()->content->prepElementContentForSave($entry, $fieldLayout);
		$content->validate();
		$entry->addErrors($content->getErrors());

		if (!$entry->hasErrors())
		{
			// Save the element record first
			$elementRecord->save(false);

			// Now that we have an element ID, save it on the other stuff
			if (!$entry->id)
			{
				$entry->id = $elementRecord->id;
				$entryRecord->id = $entry->id;
			}

			// Has the parent changed?
			if ($hasNewParent)
			{
				if ($entry->parentId === '0')
				{
					$parentEntryRecord = StructuredEntryRecord::model()->roots()->findByAttributes(array(
						'sectionId' => $section->id
					));
				}
				else
				{
					$parentEntryRecord = StructuredEntryRecord::model()->findById($entry->parentId);
				}

				if ($isNewEntry)
				{
					$entryRecord->appendTo($parentEntryRecord);
				}
				else
				{
					$entryRecord->moveAsLast($parentEntryRecord);
				}

				$entryRecord->detachBehavior('nestedSet');
			}

			$entryRecord->save(false);

			$entryLocaleRecord->entryId = $entry->id;
			$elementLocaleRecord->elementId = $entry->id;
			$content->elementId = $entry->id;

			// Save the other records
			$entryLocaleRecord->save(false);
			$elementLocaleRecord->save(false);
			craft()->content->saveContent($content, false);

			// Update the search index
			craft()->search->indexElementAttributes($entry, $entry->locale);

			// Save a new version
			if (Craft::hasPackage(CraftPackage::PublishPro))
			{
				craft()->entryRevisions->saveVersion($entry);
			}

			// Perform some post-save operations
			craft()->content->postSaveOperations($entry, $content);

			// Fire an 'onSaveEntry' event
			$this->onSaveEntry(new Event($this, array(
				'entry'      => $entry,
				'isNewEntry' => $isNewEntry
			)));

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns an entry's ancestors.
	 *
	 * @param EntryModel $entry
	 * @param int|null $delta
	 * @return array
	 */
	public function getEntryAncestors(EntryModel $entry, $delta = null)
	{
		if (Craft::hasPackage(CraftPackage::PublishPro) && $entry->getSection()->type == SectionType::Structure && $entry->depth > 1)
		{
			$criteria = craft()->elements->getCriteria(ElementType::Entry);
			$criteria->sectionId = $entry->sectionId;
			$criteria->ancestorOf = $entry;
			$criteria->ancestorDelta = $delta;
			return $criteria->find();
		}
		else
		{
			return array();
		}
	}

	/**
	 * Returns an entry's descendants.
	 *
	 * @param EntryModel $entry
	 * @param int|null $delta
	 * @return array
	 */
	public function getEntryDescendants(EntryModel $entry, $delta = null)
	{
		if (Craft::hasPackage(CraftPackage::PublishPro) && $entry->getSection()->type == SectionType::Structure)
		{
			$criteria = craft()->elements->getCriteria(ElementType::Entry);
			$criteria->sectionId = $entry->sectionId;
			$criteria->descendantOf = $entry;
			$criteria->descendantDelta = $delta;
			return $criteria->find();
		}
		else
		{
			return array();
		}
	}

	/**
	 * Appends an entry to another.
	 *
	 * @param EntryModel $entry
	 * @param EntryModel|null $parentEntry
	 * @param bool $prepend
	 * @return bool
	 */
	public function moveEntryUnder(EntryModel $entry, EntryModel $parentEntry = null, $prepend = false)
	{
		Craft::requirePackage(CraftPackage::PublishPro);

		$entryRecord = StructuredEntryRecord::model()->populateRecord($entry->getAttributes());

		if ($parentEntry)
		{
			// Make sure they're in the same section
			if ($entry->sectionId != $parentEntry->sectionId)
			{
				throw new Exception(Craft::t('That move isn’t possible.'));
			}

			$parentEntryRecord = StructuredEntryRecord::model()->populateRecord($parentEntry->getAttributes());
		}
		else
		{
			// Parent is the root node, then
			$parentEntryRecord = StructuredEntryRecord::model()->roots()->findByAttributes(array(
				'sectionId' => $entryRecord->sectionId
			));

			if (!$parentEntryRecord)
			{
				throw new Exception('There’s no root node in this section.');
			}
		}

		if ($prepend)
		{
			return $entryRecord->moveAsFirst($parentEntryRecord);
		}
		else
		{
			return $entryRecord->moveAsLast($parentEntryRecord);
		}
	}

	/**
	 * Moves an entry after another.
	 * @param EntryModel $entry
	 * @param EntryModel $prevEntry
	 * @return bool
	 */
	public function moveEntryAfter($entry, $prevEntry)
	{
		$entryRecord = StructuredEntryRecord::model()->findById($entry->id);

		if (!$entryRecord)
		{
			throw new Exception(Craft::t('No entry exists with the ID “{id}”', array('id' => $entry->id)));
		}

		$prevEntryRecord = StructuredEntryRecord::model()->findById($prevEntry->id);

		if (!$prevEntryRecord)
		{
			throw new Exception(Craft::t('No entry exists with the ID “{id}”', array('id' => $prevEntry->id)));
		}

		// Make sure they're in the same section
		if ($entryRecord->sectionId != $prevEntryRecord->sectionId)
		{
			throw new Exception(Craft::t('That move isn’t possible.'));
		}

		return $entryRecord->moveAfter($prevEntryRecord);
	}

	/**
	 * Fires an 'onSaveEntry' event.
	 *
	 * @param Event $event
	 */
	public function onSaveEntry(Event $event)
	{
		$this->raiseEvent('onSaveEntry', $event);
	}

	// Private methods
	// ===============

	/**
	 * Generates an entry slug based on its title.
	 *
	 * @access private
	 * @param EntryModel $entry
	 */
	private function _generateEntrySlug(EntryModel $entry)
	{
		$slug = ($entry->slug ? $entry->slug : $entry->getTitle());

		// Remove HTML tags
		$slug = preg_replace('/<(.*?)>/', '', $slug);

		// Make it lowercase
		$slug = mb_strtolower($slug);

		// Get the "words". This will search for any unicode "letters" or "numbers"
		preg_match_all('/[\p{L}\p{N}]+/u', $slug, $words);
		$words = ArrayHelper::filterEmptyStringsFromArray($words[0]);
		$slug = implode('-', $words);

		if ($slug)
		{
			// Make it unique
			$conditions = array('and', 'sectionId = :sectionId', 'locale = :locale', 'slug = :slug');
			$params = array(':sectionId' => $entry->sectionId, ':locale' => $entry->locale);

			if ($entry->id)
			{
				$conditions[] = 'id != :entryId';
				$params[':entryId'] = $entry->id;
			}

			for ($i = 0; true; $i++)
			{
				$testSlug = $slug.($i != 0 ? "-{$i}" : '');
				$params[':slug'] = $testSlug;

				$totalEntries = craft()->db->createCommand()
					->select('count(id)')
					->from('entries_i18n')
					->where($conditions, $params)
					->queryScalar();

				if ($totalEntries == 0)
				{
					break;
				}
			}

			$entry->slug = $testSlug;
		}
		else
		{
			$entry->slug = '';
		}
	}
}

