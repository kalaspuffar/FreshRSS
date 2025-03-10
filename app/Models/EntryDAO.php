<?php

class FreshRSS_EntryDAO extends Minz_ModelPdo implements FreshRSS_Searchable {

	public static function isCompressed(): bool {
		return true;
	}

	public static function hasNativeHex(): bool {
		return true;
	}

	protected static function sqlConcat($s1, $s2) {
		return 'CONCAT(' . $s1 . ',' . $s2 . ')';	//MySQL
	}

	public static function sqlHexDecode(string $x): string {
		return 'unhex(' . $x . ')';
	}

	public static function sqlHexEncode(string $x): string {
		return 'hex(' . $x . ')';
	}

	public static function sqlIgnoreConflict(string $sql): string {
		return str_replace('INSERT INTO ', 'INSERT IGNORE INTO ', $sql);
	}

	//TODO: Move the database auto-updates to DatabaseDAO
	protected function createEntryTempTable() {
		$ok = false;
		$hadTransaction = $this->pdo->inTransaction();
		if ($hadTransaction) {
			$this->pdo->commit();
		}
		try {
			require(APP_PATH . '/SQL/install.sql.' . $this->pdo->dbType() . '.php');
			Minz_Log::warning('SQL CREATE TABLE entrytmp...');
			$ok = $this->pdo->exec($GLOBALS['SQL_CREATE_TABLE_ENTRYTMP'] . $GLOBALS['SQL_CREATE_INDEX_ENTRY_1']) !== false;
		} catch (Exception $ex) {
			Minz_Log::error(__method__ . ' error: ' . $ex->getMessage());
		}
		if ($hadTransaction) {
			$this->pdo->beginTransaction();
		}
		return $ok;
	}

	private function updateToMediumBlob() {
		if ($this->pdo->dbType() !== 'mysql') {
			return false;
		}
		Minz_Log::warning('Update MySQL table to use MEDIUMBLOB...');

		$sql = <<<'SQL'
ALTER TABLE `_entry` MODIFY `content_bin` MEDIUMBLOB;
ALTER TABLE `_entrytmp` MODIFY `content_bin` MEDIUMBLOB;
SQL;
		try {
			$ok = $this->pdo->exec($sql) !== false;
		} catch (Exception $e) {
			$ok = false;
			Minz_Log::error(__method__ . ' error: ' . $e->getMessage());
		}
		return $ok;
	}

	protected function addColumn(string $name) {
		if ($this->pdo->inTransaction()) {
			$this->pdo->commit();
		}
		Minz_Log::warning(__method__ . ': ' . $name);
		try {
			if ($name === 'attributes') {	//v1.20.0
				$sql = <<<'SQL'
ALTER TABLE `_entry` ADD COLUMN attributes TEXT;
ALTER TABLE `_entrytmp` ADD COLUMN attributes TEXT;
SQL;
				return $this->pdo->exec($sql) !== false;
			}
		} catch (Exception $e) {
			Minz_Log::error(__method__ . ' error: ' . $e->getMessage());
		}
		return false;
	}

	//TODO: Move the database auto-updates to DatabaseDAO
	protected function autoUpdateDb(array $errorInfo) {
		if (isset($errorInfo[0])) {
			if ($errorInfo[0] === FreshRSS_DatabaseDAO::ER_BAD_FIELD_ERROR || $errorInfo[0] === FreshRSS_DatabaseDAOPGSQL::UNDEFINED_COLUMN) {
				$errorLines = explode("\n", $errorInfo[2], 2);	// The relevant column name is on the first line, other lines are noise
				foreach (['attributes'] as $column) {
					if (stripos($errorLines[0], $column) !== false) {
						return $this->addColumn($column);
					}
				}
			}
			if ($errorInfo[0] === FreshRSS_DatabaseDAO::ER_BAD_TABLE_ERROR) {
				if (stripos($errorInfo[2], 'tag') !== false) {
					$tagDAO = FreshRSS_Factory::createTagDao();
					return $tagDAO->createTagTable();	//v1.12.0
				} elseif (stripos($errorInfo[2], 'entrytmp') !== false) {
					return $this->createEntryTempTable();	//v1.7.0
				}
			}
		}
		if (isset($errorInfo[1])) {
			if ($errorInfo[1] == FreshRSS_DatabaseDAO::ER_DATA_TOO_LONG) {
				if (stripos($errorInfo[2], 'content_bin') !== false) {
					return $this->updateToMediumBlob();	//v1.15.0
				}
			}
		}
		return false;
	}

	/**
	 * @var PDOStatement|null|false
	 */
	private $addEntryPrepared = false;

	public function addEntry(array $valuesTmp, bool $useTmpTable = true) {
		if ($this->addEntryPrepared == null) {
			$sql = static::sqlIgnoreConflict(
				'INSERT INTO `_' . ($useTmpTable ? 'entrytmp' : 'entry') . '` (id, guid, title, author, '
				. (static::isCompressed() ? 'content_bin' : 'content')
				. ', link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes) '
				. 'VALUES(:id, :guid, :title, :author, '
				. (static::isCompressed() ? 'COMPRESS(:content)' : ':content')
				. ', :link, :date, :last_seen, '
				. static::sqlHexDecode(':hash')
				. ', :is_read, :is_favorite, :id_feed, :tags, :attributes)');
			$this->addEntryPrepared = $this->pdo->prepare($sql);
		}
		if ($this->addEntryPrepared) {
			$this->addEntryPrepared->bindParam(':id', $valuesTmp['id']);
			$valuesTmp['guid'] = substr($valuesTmp['guid'], 0, 760);
			$valuesTmp['guid'] = safe_ascii($valuesTmp['guid']);
			$this->addEntryPrepared->bindParam(':guid', $valuesTmp['guid']);
			$valuesTmp['title'] = mb_strcut($valuesTmp['title'], 0, 255, 'UTF-8');
			$valuesTmp['title'] = safe_utf8($valuesTmp['title']);
			$this->addEntryPrepared->bindParam(':title', $valuesTmp['title']);
			$valuesTmp['author'] = mb_strcut($valuesTmp['author'], 0, 255, 'UTF-8');
			$valuesTmp['author'] = safe_utf8($valuesTmp['author']);
			$this->addEntryPrepared->bindParam(':author', $valuesTmp['author']);
			$valuesTmp['content'] = safe_utf8($valuesTmp['content']);
			$this->addEntryPrepared->bindParam(':content', $valuesTmp['content']);
			$valuesTmp['link'] = substr($valuesTmp['link'], 0, 1023);
			$valuesTmp['link'] = safe_ascii($valuesTmp['link']);
			$this->addEntryPrepared->bindParam(':link', $valuesTmp['link']);
			$valuesTmp['date'] = min($valuesTmp['date'], 2147483647);
			$this->addEntryPrepared->bindParam(':date', $valuesTmp['date'], PDO::PARAM_INT);
			if (empty($valuesTmp['lastSeen'])) {
				$valuesTmp['lastSeen'] = time();
			}
			$this->addEntryPrepared->bindParam(':last_seen', $valuesTmp['lastSeen'], PDO::PARAM_INT);
			$valuesTmp['is_read'] = $valuesTmp['is_read'] ? 1 : 0;
			$this->addEntryPrepared->bindParam(':is_read', $valuesTmp['is_read'], PDO::PARAM_INT);
			$valuesTmp['is_favorite'] = $valuesTmp['is_favorite'] ? 1 : 0;
			$this->addEntryPrepared->bindParam(':is_favorite', $valuesTmp['is_favorite'], PDO::PARAM_INT);
			$this->addEntryPrepared->bindParam(':id_feed', $valuesTmp['id_feed'], PDO::PARAM_INT);
			$valuesTmp['tags'] = mb_strcut($valuesTmp['tags'], 0, 1023, 'UTF-8');
			$valuesTmp['tags'] = safe_utf8($valuesTmp['tags']);
			$this->addEntryPrepared->bindParam(':tags', $valuesTmp['tags']);
			if (!isset($valuesTmp['attributes'])) {
				$valuesTmp['attributes'] = [];
			}
			$this->addEntryPrepared->bindValue(':attributes', is_string($valuesTmp['attributes']) ? $valuesTmp['attributes'] :
				json_encode($valuesTmp['attributes'], JSON_UNESCAPED_SLASHES));

			if (static::hasNativeHex()) {
				$this->addEntryPrepared->bindParam(':hash', $valuesTmp['hash']);
			} else {
				$valuesTmp['hashBin'] = hex2bin($valuesTmp['hash']);
				$this->addEntryPrepared->bindParam(':hash', $valuesTmp['hashBin']);
			}
		}
		if ($this->addEntryPrepared && $this->addEntryPrepared->execute()) {
			return true;
		} else {
			$info = $this->addEntryPrepared == null ? $this->pdo->errorInfo() : $this->addEntryPrepared->errorInfo();
			if ($this->autoUpdateDb($info)) {
				$this->addEntryPrepared = null;
				return $this->addEntry($valuesTmp);
			} elseif ((int)((int)$info[0] / 1000) !== 23) {	//Filter out "SQLSTATE Class code 23: Constraint Violation" because of expected duplicate entries
				Minz_Log::error('SQL error addEntry: ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
					. ' while adding entry in feed ' . $valuesTmp['id_feed'] . ' with title: ' . $valuesTmp['title']);
			}
			return false;
		}
	}

	public function commitNewEntries() {
		$sql = <<<'SQL'
SET @rank=(SELECT MAX(id) - COUNT(*) FROM `_entrytmp`);

INSERT IGNORE INTO `_entry` (
	id, guid, title, author, content_bin, link, date, `lastSeen`,
	hash, is_read, is_favorite, id_feed, tags, attributes
)
SELECT @rank:=@rank+1 AS id, guid, title, author, content_bin, link, date, `lastSeen`, hash, is_read, is_favorite, id_feed, tags, attributes
FROM `_entrytmp`
ORDER BY date, id;

DELETE FROM `_entrytmp` WHERE id <= @rank;
SQL;
		$hadTransaction = $this->pdo->inTransaction();
		if (!$hadTransaction) {
			$this->pdo->beginTransaction();
		}
		$result = $this->pdo->exec($sql) !== false;
		if (!$hadTransaction) {
			$this->pdo->commit();
		}
		return $result;
	}

	private $updateEntryPrepared = null;

	public function updateEntry(array $valuesTmp) {
		if (!isset($valuesTmp['is_read'])) {
			$valuesTmp['is_read'] = null;
		}
		if (!isset($valuesTmp['is_favorite'])) {
			$valuesTmp['is_favorite'] = null;
		}

		if ($this->updateEntryPrepared === null) {
			$sql = 'UPDATE `_entry` '
				. 'SET title=:title, author=:author, '
				. (static::isCompressed() ? 'content_bin=COMPRESS(:content)' : 'content=:content')
				. ', link=:link, date=:date, `lastSeen`=:last_seen'
				. ', hash=' . static::sqlHexDecode(':hash')
				. ', is_read=COALESCE(:is_read, is_read)'
				. ', is_favorite=COALESCE(:is_favorite, is_favorite)'
				. ', tags=:tags, attributes=:attributes '
				. 'WHERE id_feed=:id_feed AND guid=:guid';
			$this->updateEntryPrepared = $this->pdo->prepare($sql);
		}
		if ($this->updateEntryPrepared) {
			$valuesTmp['guid'] = substr($valuesTmp['guid'], 0, 760);
			$valuesTmp['guid'] = safe_ascii($valuesTmp['guid']);
			$this->updateEntryPrepared->bindParam(':guid', $valuesTmp['guid']);
			$valuesTmp['title'] = mb_strcut($valuesTmp['title'], 0, 255, 'UTF-8');
			$valuesTmp['title'] = safe_utf8($valuesTmp['title']);
			$this->updateEntryPrepared->bindParam(':title', $valuesTmp['title']);
			$valuesTmp['author'] = mb_strcut($valuesTmp['author'], 0, 255, 'UTF-8');
			$valuesTmp['author'] = safe_utf8($valuesTmp['author']);
			$this->updateEntryPrepared->bindParam(':author', $valuesTmp['author']);
			$valuesTmp['content'] = safe_utf8($valuesTmp['content']);
			$this->updateEntryPrepared->bindParam(':content', $valuesTmp['content']);
			$valuesTmp['link'] = substr($valuesTmp['link'], 0, 1023);
			$valuesTmp['link'] = safe_ascii($valuesTmp['link']);
			$this->updateEntryPrepared->bindParam(':link', $valuesTmp['link']);
			$valuesTmp['date'] = min($valuesTmp['date'], 2147483647);
			$this->updateEntryPrepared->bindParam(':date', $valuesTmp['date'], PDO::PARAM_INT);
			$valuesTmp['lastSeen'] = time();
			$this->updateEntryPrepared->bindParam(':last_seen', $valuesTmp['lastSeen'], PDO::PARAM_INT);
			if ($valuesTmp['is_read'] === null) {
				$this->updateEntryPrepared->bindValue(':is_read', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':is_read', $valuesTmp['is_read'] ? 1 : 0, PDO::PARAM_INT);
			}
			if ($valuesTmp['is_favorite'] === null) {
				$this->updateEntryPrepared->bindValue(':is_favorite', null, PDO::PARAM_NULL);
			} else {
				$this->updateEntryPrepared->bindValue(':is_favorite', $valuesTmp['is_favorite'] ? 1 : 0, PDO::PARAM_INT);
			}
			$this->updateEntryPrepared->bindParam(':id_feed', $valuesTmp['id_feed'], PDO::PARAM_INT);
			$valuesTmp['tags'] = mb_strcut($valuesTmp['tags'], 0, 1023, 'UTF-8');
			$valuesTmp['tags'] = safe_utf8($valuesTmp['tags']);
			$this->updateEntryPrepared->bindParam(':tags', $valuesTmp['tags']);
			if (!isset($valuesTmp['attributes'])) {
				$valuesTmp['attributes'] = [];
			}
			$this->updateEntryPrepared->bindValue(':attributes', is_string($valuesTmp['attributes']) ? $valuesTmp['attributes'] :
				json_encode($valuesTmp['attributes'], JSON_UNESCAPED_SLASHES));

			if (static::hasNativeHex()) {
				$this->updateEntryPrepared->bindParam(':hash', $valuesTmp['hash']);
			} else {
				$valuesTmp['hashBin'] = hex2bin($valuesTmp['hash']);
				$this->updateEntryPrepared->bindParam(':hash', $valuesTmp['hashBin']);
			}
		}

		if ($this->updateEntryPrepared && $this->updateEntryPrepared->execute()) {
			return true;
		} else {
			$info = $this->updateEntryPrepared == null ? $this->pdo->errorInfo() : $this->updateEntryPrepared->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->updateEntry($valuesTmp);
			}
			Minz_Log::error('SQL error updateEntry: ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
				. ' while updating entry with GUID ' . $valuesTmp['guid'] . ' in feed ' . $valuesTmp['id_feed']);
			return false;
		}
	}

	/**
	 * Toggle favorite marker on one or more article
	 *
	 * @todo simplify the query by removing the str_repeat. I am pretty sure
	 * there is an other way to do that.
	 *
	 * @param integer|array $ids
	 * @return false|integer
	 */
	public function markFavorite($ids, bool $is_favorite = true) {
		if (!is_array($ids)) {
			$ids = array($ids);
		}
		if (count($ids) < 1) {
			return 0;
		}
		FreshRSS_UserDAO::touch();
		if (count($ids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
			// Split a query with too many variables parameters
			$affected = 0;
			$idsChunks = array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
			foreach ($idsChunks as $idsChunk) {
				$affected += $this->markFavorite($idsChunk, $is_favorite);
			}
			return $affected;
		}
		$sql = 'UPDATE `_entry` '
			. 'SET is_favorite=? '
			. 'WHERE id IN (' . str_repeat('?,', count($ids) - 1). '?)';
		$values = array($is_favorite ? 1 : 0);
		$values = array_merge($values, $ids);
		$stm = $this->pdo->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markFavorite: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Update the unread article cache held on every feed details.
	 * Depending on the parameters, it updates the cache on one feed, on all
	 * feeds from one category or on all feeds.
	 *
	 * @todo It can use the query builder refactoring to build that query
	 *
	 * @param false|integer $catId category ID
	 * @param false|integer $feedId feed ID
	 * @return boolean
	 */
	protected function updateCacheUnreads($catId = false, $feedId = false) {
		$sql = 'UPDATE `_feed` f '
			. 'LEFT OUTER JOIN ('
			.	'SELECT e.id_feed, '
			.	'COUNT(*) AS nbUnreads '
			.	'FROM `_entry` e '
			.	'WHERE e.is_read=0 '
			.	'GROUP BY e.id_feed'
			. ') x ON x.id_feed=f.id '
			. 'SET f.`cache_nbUnreads`=COALESCE(x.nbUnreads, 0)';
		$hasWhere = false;
		$values = array();
		if ($feedId !== false) {
			$sql .= ' WHERE';
			$hasWhere = true;
			$sql .= ' f.id=?';
			$values[] = $feedId;
		}
		if ($catId !== false) {
			$sql .= $hasWhere ? ' AND' : ' WHERE';
			$hasWhere = true;
			$sql .= ' f.category=?';
			$values[] = $catId;
		}
		$stm = $this->pdo->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return true;
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error updateCacheUnreads: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Toggle the read marker on one or more article.
	 * Then the cache is updated.
	 *
	 * @todo change the way the query is build because it seems there is
	 * unnecessary code in here. For instance, the part with the str_repeat.
	 * @todo remove code duplication. It seems the code is basically the
	 * same if it is an array or not.
	 *
	 * @param integer|array $ids
	 * @param boolean $is_read
	 * @return integer|false affected rows
	 */
	public function markRead($ids, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if (is_array($ids)) {	//Many IDs at once
			if (count($ids) < 6) {	//Speed heuristics
				$affected = 0;
				foreach ($ids as $id) {
					$affected += $this->markRead($id, $is_read);
				}
				return $affected;
			} elseif (count($ids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
				// Split a query with too many variables parameters
				$affected = 0;
				$idsChunks = array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
				foreach ($idsChunks as $idsChunk) {
					$affected += $this->markRead($idsChunk, $is_read);
				}
				return $affected;
			}

			$sql = 'UPDATE `_entry` '
				 . 'SET is_read=? '
				 . 'WHERE id IN (' . str_repeat('?,', count($ids) - 1). '?)';
			$values = array($is_read ? 1 : 0);
			$values = array_merge($values, $ids);
			$stm = $this->pdo->prepare($sql);
			if (!($stm && $stm->execute($values))) {
				$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error markRead: ' . $info[2]);
				return false;
			}
			$affected = $stm->rowCount();
			if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
				return false;
			}
			return $affected;
		} else {
			$sql = 'UPDATE `_entry` e INNER JOIN `_feed` f ON e.id_feed=f.id '
				 . 'SET e.is_read=?,'
				 . 'f.`cache_nbUnreads`=f.`cache_nbUnreads`' . ($is_read ? '-' : '+') . '1 '
				 . 'WHERE e.id=? AND e.is_read=?';
			$values = array($is_read ? 1 : 0, $ids, $is_read ? 0 : 1);
			$stm = $this->pdo->prepare($sql);
			if ($stm && $stm->execute($values)) {
				return $stm->rowCount();
			} else {
				$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error markRead: ' . $info[2]);
				return false;
			}
		}
	}

	/**
	 * Mark all entries as read depending on parameters.
	 * If $onlyFavorites is true, it is used when the user mark as read in
	 * the favorite pseudo-category.
	 * If $priorityMin is greater than 0, it is used when the user mark as
	 * read in the main feed pseudo-category.
	 * Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @todo refactor this method along with markReadCat and markReadFeed
	 * since they are all doing the same thing. I think we need to build a
	 * tool to generate the query instead of having queries all over the
	 * place. It will be reused also for the filtering making every thing
	 * separated.
	 *
	 * @param string $idMax fail safe article ID
	 * @param boolean $onlyFavorites
	 * @param integer $priorityMin
	 * @param FreshRSS_BooleanSearch|null $filters
	 * @return integer|false affected rows
	 */
	public function markReadEntries(string $idMax = '0', bool $onlyFavorites = false, int $priorityMin = 0, $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == 0) {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadEntries(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` e INNER JOIN `_feed` f ON e.id_feed=f.id '
			 . 'SET e.is_read=? '
			 . 'WHERE e.is_read <> ? AND e.id <= ?';
		if ($onlyFavorites) {
			$sql .= ' AND e.is_favorite=1';
		} elseif ($priorityMin >= 0) {
			$sql .= ' AND f.priority > ' . intval($priorityMin);
		}
		$values = array($is_read ? 1 : 0, $is_read ? 1 : 0, $idMax);

		list($searchValues, $search) = $this->sqlListEntriesWhere('e.', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadEntries: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a category as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param integer $id category ID
	 * @param string $idMax fail safe article ID
	 * @param FreshRSS_BooleanSearch|null $filters
	 * @return integer|false affected rows
	 */
	public function markReadCat(int $id, string $idMax = '0', $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadCat(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` e INNER JOIN `_feed` f ON e.id_feed=f.id '
			 . 'SET e.is_read=? '
			 . 'WHERE f.category=? AND e.is_read <> ? AND e.id <= ?';
		$values = array($is_read ? 1 : 0, $id, $is_read ? 1 : 0, $idMax);

		list($searchValues, $search) = $this->sqlListEntriesWhere('e.', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadCat: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads($id, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a feed as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param integer $id_feed feed ID
	 * @param string $idMax fail safe article ID
	 * @param FreshRSS_BooleanSearch|null $filters
	 * @return integer|false affected rows
	 */
	public function markReadFeed(int $id_feed, string $idMax = '0', $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadFeed(0) is deprecated!');
		}
		$this->pdo->beginTransaction();

		$sql = 'UPDATE `_entry` '
			 . 'SET is_read=? '
			 . 'WHERE id_feed=? AND is_read <> ? AND id <= ?';
		$values = array($is_read ? 1 : 0, $id_feed, $is_read ? 1 : 0, $idMax);

		list($searchValues, $search) = $this->sqlListEntriesWhere('', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadFeed: ' . $info[2] . ' with SQL: ' . $sql . $search);
			$this->pdo->rollBack();
			return false;
		}
		$affected = $stm->rowCount();

		if ($affected > 0) {
			$sql = 'UPDATE `_feed` '
				 . 'SET `cache_nbUnreads`=`cache_nbUnreads`-' . $affected
				 . ' WHERE id=:id';
			$stm = $this->pdo->prepare($sql);
			$stm->bindParam(':id', $id_feed, PDO::PARAM_INT);
			if (!($stm && $stm->execute())) {
				$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
				Minz_Log::error('SQL error markReadFeed cache: ' . $info[2]);
				$this->pdo->rollBack();
				return false;
			}
		}

		$this->pdo->commit();
		return $affected;
	}

	/**
	 * Mark all the articles in a tag as read.
	 * @param integer $id tag ID, or empty for targeting any tag
	 * @param string $idMax max article ID
	 * @return integer|false affected rows
	 */
	public function markReadTag($id = 0, string $idMax = '0', $filters = null, int $state = 0, bool $is_read = true) {
		FreshRSS_UserDAO::touch();
		if ($idMax == '0') {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadTag(0) is deprecated!');
		}

		$sql = 'UPDATE `_entry` e INNER JOIN `_entrytag` et ON et.id_entry = e.id '
			 . 'SET e.is_read = ? '
			 . 'WHERE '
			 . ($id == 0 ? '' : 'et.id_tag = ? AND ')
			 . 'e.is_read <> ? AND e.id <= ?';
		$values = array($is_read ? 1 : 0);
		if ($id != 0) {
			$values[] = $id;
		}
		$values[] = $is_read ? 1 : 0;
		$values[] = $idMax;

		list($searchValues, $search) = $this->sqlListEntriesWhere('e.', $filters, $state);

		$stm = $this->pdo->prepare($sql . $search);
		if (!($stm && $stm->execute(array_merge($values, $searchValues)))) {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			Minz_Log::error('SQL error markReadTag: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Remember to call updateCachedValue($id_feed) or updateCachedValues() just after.
	 */
	public function cleanOldEntries($id_feed, $options = []) {
		$sql = 'DELETE FROM `_entry` WHERE id_feed = :id_feed1';	//No alias for MySQL / MariaDB
		$params = [];
		$params[':id_feed1'] = $id_feed;

		//==Exclusions==
		if (!empty($options['keep_favourites'])) {
			$sql .= ' AND is_favorite = 0';
		}
		if (!empty($options['keep_unreads'])) {
			$sql .= ' AND is_read = 1';
		}
		if (!empty($options['keep_labels'])) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM `_entrytag` WHERE id_entry = id)';
		}
		if (!empty($options['keep_min']) && $options['keep_min'] > 0) {
			//Double SELECT for MySQL workaround ERROR 1093 (HY000)
			$sql .= ' AND `lastSeen` < (SELECT `lastSeen`'
				. ' FROM (SELECT e2.`lastSeen` FROM `_entry` e2 WHERE e2.id_feed = :id_feed2'
				. ' ORDER BY e2.`lastSeen` DESC LIMIT 1 OFFSET :keep_min) last_seen2)';
			$params[':id_feed2'] = $id_feed;
			$params[':keep_min'] = (int)$options['keep_min'];
		}
		//Keep at least the articles seen at the last refresh
		$sql .= ' AND `lastSeen` < (SELECT maxlastseen'
			. ' FROM (SELECT MAX(e3.`lastSeen`) AS maxlastseen FROM `_entry` e3 WHERE e3.id_feed = :id_feed3) last_seen3)';
		$params[':id_feed3'] = $id_feed;

		//==Inclusions==
		$sql .= ' AND (1=0';
		if (!empty($options['keep_period'])) {
			$sql .= ' OR `lastSeen` < :max_last_seen';
			$now = new DateTime('now');
			$now->sub(new DateInterval($options['keep_period']));
			$params[':max_last_seen'] = $now->format('U');
		}
		if (!empty($options['keep_max']) && $options['keep_max'] > 0) {
			$sql .= ' OR `lastSeen` <= (SELECT `lastSeen`'
				. ' FROM (SELECT e4.`lastSeen` FROM `_entry` e4 WHERE e4.id_feed = :id_feed4'
				. ' ORDER BY e4.`lastSeen` DESC LIMIT 1 OFFSET :keep_max) last_seen4)';
			$params[':id_feed4'] = $id_feed;
			$params[':keep_max'] = (int)$options['keep_max'];
		}
		$sql .= ')';

		$stm = $this->pdo->prepare($sql);

		if ($stm && $stm->execute($params)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->cleanOldEntries($id_feed, $options);
			}
			Minz_Log::error(__method__ . ' error:' . json_encode($info));
			return false;
		}
	}

	public function selectAll() {
		$sql = 'SELECT id, guid, title, author, '
			. (static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', link, date, `lastSeen`, ' . static::sqlHexEncode('hash') . ' AS hash, is_read, is_favorite, id_feed, tags, attributes '
			. 'FROM `_entry`';
		$stm = $this->pdo->query($sql);
		if ($stm != false) {
			while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
				yield $row;
			}
		} else {
			$info = $this->pdo->errorInfo();
			if ($this->autoUpdateDb($info)) {
				yield from $this->selectAll();
			}
			Minz_Log::error(__method__ . ' error: ' . json_encode($info));
			yield false;
		}
	}

	/** @return FreshRSS_Entry|null */
	public function searchByGuid($id_feed, $guid) {
		// un guid est unique pour un flux donné
		$sql = 'SELECT id, guid, title, author, '
			. (static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', link, date, is_read, is_favorite, id_feed, tags, attributes '
			. 'FROM `_entry` WHERE id_feed=:id_feed AND guid=:guid';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id_feed', $id_feed, PDO::PARAM_INT);
		$stm->bindParam(':guid', $guid);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		return isset($res[0]) ? FreshRSS_Entry::fromArray($res[0]) : null;
	}

	/** @return FreshRSS_Entry|null */
	public function searchById($id) {
		$sql = 'SELECT id, guid, title, author, '
			. (static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', link, date, is_read, is_favorite, id_feed, tags, attributes '
			. 'FROM `_entry` WHERE id=:id';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id', $id, PDO::PARAM_INT);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		return isset($res[0]) ? FreshRSS_Entry::fromArray($res[0]) : null;
	}

	public function searchIdByGuid($id_feed, $guid) {
		$sql = 'SELECT id FROM `_entry` WHERE id_feed=:id_feed AND guid=:guid';
		$stm = $this->pdo->prepare($sql);
		$stm->bindParam(':id_feed', $id_feed, PDO::PARAM_INT);
		$stm->bindParam(':guid', $guid);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return isset($res[0]) ? $res[0] : null;
	}

	/** @param FreshRSS_BooleanSearch $filters */
	public static function sqlBooleanSearch(string $alias, $filters, int $level = 0) {
		$search = '';
		$values = [];

		$isOpen = false;
		foreach ($filters->searches() as $filter) {
			if ($filter == null) {
				continue;
			}
			if ($filter instanceof FreshRSS_BooleanSearch) {
				// BooleanSearches are combined by AND (default) or OR (special case) operator and are recursive
				list($filterValues, $filterSearch) = self::sqlBooleanSearch($alias, $filter, $level + 1);
				$filterSearch = trim($filterSearch);

				if ($filterSearch !== '') {
					if ($search !== '') {
						$search .= $filter->operator();
					} elseif ($filter->operator() === 'AND NOT') {
						// Special case if we start with a negation (there is already the default AND before)
						$search .= ' NOT';
					}
					$search .= ' (' . $filterSearch . ') ';
					$values = array_merge($values, $filterValues);
				}
				continue;
			}
			// Searches are combined by OR and are not recursive
			$sub_search = '';
			if ($filter->getEntryIds()) {
				foreach ($filter->getEntryIds() as $entry_ids) {
					$sub_search .= 'AND ' . $alias . 'id IN (';
					foreach ($entry_ids as $entry_id) {
						$sub_search .= '?,';
						$values[] = $entry_id;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ') ';
				}
			}
			if ($filter->getNotEntryIds()) {
				foreach ($filter->getNotEntryIds() as $entry_ids) {
					$sub_search .= 'AND ' . $alias . 'id NOT IN (';
					foreach ($entry_ids as $entry_id) {
						$sub_search .= '?,';
						$values[] = $entry_id;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ') ';
				}
			}

			if ($filter->getMinDate()) {
				$sub_search .= 'AND ' . $alias . 'id >= ? ';
				$values[] = "{$filter->getMinDate()}000000";
			}
			if ($filter->getMaxDate()) {
				$sub_search .= 'AND ' . $alias . 'id <= ? ';
				$values[] = "{$filter->getMaxDate()}000000";
			}
			if ($filter->getMinPubdate()) {
				$sub_search .= 'AND ' . $alias . 'date >= ? ';
				$values[] = $filter->getMinPubdate();
			}
			if ($filter->getMaxPubdate()) {
				$sub_search .= 'AND ' . $alias . 'date <= ? ';
				$values[] = $filter->getMaxPubdate();
			}

			//Negation of date intervals must be combined by OR
			if ($filter->getNotMinDate() || $filter->getNotMaxDate()) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinDate()) {
					$sub_search .= $alias . 'id < ?';
					$values[] = "{$filter->getNotMinDate()}000000";
					if ($filter->getNotMaxDate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxDate()) {
					$sub_search .= $alias . 'id > ?';
					$values[] = "{$filter->getNotMaxDate()}000000";
				}
				$sub_search .= ') ';
			}
			if ($filter->getNotMinPubdate() || $filter->getNotMaxPubdate()) {
				$sub_search .= 'AND (';
				if ($filter->getNotMinPubdate()) {
					$sub_search .= $alias . 'date < ?';
					$values[] = $filter->getNotMinPubdate();
					if ($filter->getNotMaxPubdate()) {
						$sub_search .= ' OR ';
					}
				}
				if ($filter->getNotMaxPubdate()) {
					$sub_search .= $alias . 'date > ?';
					$values[] = $filter->getNotMaxPubdate();
				}
				$sub_search .= ') ';
			}

			if ($filter->getFeedIds()) {
				foreach ($filter->getFeedIds() as $feed_ids) {
					$sub_search .= 'AND ' . $alias . 'id_feed IN (';
					foreach ($feed_ids as $feed_id) {
						$sub_search .= '?,';
						$values[] = $feed_id;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ') ';
				}
			}
			if ($filter->getNotFeedIds()) {
				foreach ($filter->getNotFeedIds() as $feed_ids) {
					$sub_search .= 'AND ' . $alias . 'id_feed NOT IN (';
					foreach ($feed_ids as $feed_id) {
						$sub_search .= '?,';
						$values[] = $feed_id;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ') ';
				}
			}

			if ($filter->getLabelIds()) {
				foreach ($filter->getLabelIds() as $label_ids) {
					if ($label_ids === '*') {
						$sub_search .= 'AND EXISTS (SELECT et.id_tag FROM `_entrytag` et WHERE et.id_entry = ' . $alias . 'id) ';
					} else {
						$sub_search .= 'AND ' . $alias . 'id IN (SELECT et.id_entry FROM `_entrytag` et WHERE et.id_tag IN (';
						foreach ($label_ids as $label_id) {
							$sub_search .= '?,';
							$values[] = $label_id;
						}
						$sub_search = rtrim($sub_search, ',');
						$sub_search .= ')) ';
					}
				}
			}
			if ($filter->getNotLabelIds()) {
				foreach ($filter->getNotLabelIds() as $label_ids) {
					if ($label_ids === '*') {
						$sub_search .= 'AND NOT EXISTS (SELECT et.id_tag FROM `_entrytag` et WHERE et.id_entry = ' . $alias . 'id) ';
					} else {
						$sub_search .= 'AND ' . $alias . 'id NOT IN (SELECT et.id_entry FROM `_entrytag` et WHERE et.id_tag IN (';
						foreach ($label_ids as $label_id) {
							$sub_search .= '?,';
							$values[] = $label_id;
						}
						$sub_search = rtrim($sub_search, ',');
						$sub_search .= ')) ';
					}
				}
			}

			if ($filter->getLabelNames()) {
				foreach ($filter->getLabelNames() as $label_names) {
					$sub_search .= 'AND ' . $alias . 'id IN (SELECT et.id_entry FROM `_entrytag` et, `_tag` t WHERE et.id_tag = t.id AND t.name IN (';
					foreach ($label_names as $label_name) {
						$sub_search .= '?,';
						$values[] = $label_name;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ')) ';
				}
			}
			if ($filter->getNotLabelNames()) {
				foreach ($filter->getNotLabelNames() as $label_names) {
					$sub_search .= 'AND ' . $alias . 'id NOT IN (SELECT et.id_entry FROM `_entrytag` et, `_tag` t WHERE et.id_tag = t.id AND t.name IN (';
					foreach ($label_names as $label_name) {
						$sub_search .= '?,';
						$values[] = $label_name;
					}
					$sub_search = rtrim($sub_search, ',');
					$sub_search .= ')) ';
				}
			}

			if ($filter->getAuthor()) {
				foreach ($filter->getAuthor() as $author) {
					$sub_search .= 'AND ' . $alias . 'author LIKE ? ';
					$values[] = "%{$author}%";
				}
			}
			if ($filter->getIntitle()) {
				foreach ($filter->getIntitle() as $title) {
					$sub_search .= 'AND ' . $alias . 'title LIKE ? ';
					$values[] = "%{$title}%";
				}
			}
			if ($filter->getTags()) {
				foreach ($filter->getTags() as $tag) {
					$sub_search .= 'AND ' . static::sqlConcat('TRIM(' . $alias . 'tags) ', " ' #'") . ' LIKE ? ';
					$values[] = "%{$tag} #%";
				}
			}
			if ($filter->getInurl()) {
				foreach ($filter->getInurl() as $url) {
					$sub_search .= 'AND ' . $alias . 'link LIKE ? ';
					$values[] = "%{$url}%";
				}
			}

			if ($filter->getNotAuthor()) {
				foreach ($filter->getNotAuthor() as $author) {
					$sub_search .= 'AND ' . $alias . 'author NOT LIKE ? ';
					$values[] = "%{$author}%";
				}
			}
			if ($filter->getNotIntitle()) {
				foreach ($filter->getNotIntitle() as $title) {
					$sub_search .= 'AND ' . $alias . 'title NOT LIKE ? ';
					$values[] = "%{$title}%";
				}
			}
			if ($filter->getNotTags()) {
				foreach ($filter->getNotTags() as $tag) {
					$sub_search .= 'AND ' . static::sqlConcat('TRIM(' . $alias . 'tags) ', " ' #'") . ' NOT LIKE ? ';
					$values[] = "%{$tag} #%";
				}
			}
			if ($filter->getNotInurl()) {
				foreach ($filter->getNotInurl() as $url) {
					$sub_search .= 'AND ' . $alias . 'link NOT LIKE ? ';
					$values[] = "%{$url}%";
				}
			}

			if ($filter->getSearch()) {
				foreach ($filter->getSearch() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= 'AND CONCAT(' . $alias . 'title, UNCOMPRESS(' . $alias . 'content_bin)) LIKE ? ';
						$values[] = "%{$search_value}%";
					} else {
						$sub_search .= 'AND (' . $alias . 'title LIKE ? OR ' . $alias . 'content LIKE ?) ';
						$values[] = "%{$search_value}%";
						$values[] = "%{$search_value}%";
					}
				}
			}
			if ($filter->getNotSearch()) {
				foreach ($filter->getNotSearch() as $search_value) {
					if (static::isCompressed()) {	// MySQL-only
						$sub_search .= 'AND CONCAT(' . $alias . 'title, UNCOMPRESS(' . $alias . 'content_bin)) NOT LIKE ? ';
						$values[] = "%{$search_value}%";
					} else {
						$sub_search .= 'AND ' . $alias . 'title NOT LIKE ? AND ' . $alias . 'content NOT LIKE ? ';
						$values[] = "%{$search_value}%";
						$values[] = "%{$search_value}%";
					}
				}
			}

			if ($sub_search != '') {
				if ($isOpen) {
					$search .= ' OR ';
				} else {
					$isOpen = true;
				}
				// Remove superfluous leading 'AND '
				$search .= '(' . substr($sub_search, 4) . ')';
			}
		}

		return [ $values, $search ];
	}

	/** @param FreshRSS_BooleanSearch|null $filters */
	protected function sqlListEntriesWhere(string $alias = '', $filters = null, int $state = FreshRSS_Entry::STATE_ALL,
			string $order = 'DESC', string $firstId = '', int $date_min = 0) {
		$search = ' ';
		$values = array();
		if ($state & FreshRSS_Entry::STATE_NOT_READ) {
			if (!($state & FreshRSS_Entry::STATE_READ)) {
				$search .= 'AND ' . $alias . 'is_read=0 ';
			}
		} elseif ($state & FreshRSS_Entry::STATE_READ) {
			$search .= 'AND ' . $alias . 'is_read=1 ';
		}
		if ($state & FreshRSS_Entry::STATE_FAVORITE) {
			if (!($state & FreshRSS_Entry::STATE_NOT_FAVORITE)) {
				$search .= 'AND ' . $alias . 'is_favorite=1 ';
			}
		} elseif ($state & FreshRSS_Entry::STATE_NOT_FAVORITE) {
			$search .= 'AND ' . $alias . 'is_favorite=0 ';
		}

		switch ($order) {
			case 'DESC':
			case 'ASC':
				break;
			default:
				throw new FreshRSS_EntriesGetter_Exception('Bad order in Entry->listByType: [' . $order . ']!');
		}
		if ($firstId !== '') {
			$search .= 'AND ' . $alias . 'id ' . ($order === 'DESC' ? '<=' : '>=') . ' ? ';
			$values[] = $firstId;
		}
		if ($date_min > 0) {
			$search .= 'AND ' . $alias . 'id >= ? ';
			$values[] = $date_min . '000000';
		}
		if ($filters && count($filters->searches()) > 0) {
			list($filterValues, $filterSearch) = self::sqlBooleanSearch($alias, $filters);
			$filterSearch = trim($filterSearch);
			if ($filterSearch !== '') {
				$search .= 'AND (' . $filterSearch . ') ';
				$values = array_merge($values, $filterValues);
			}
		}
		return array($values, $search);
	}

	private function sqlListWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL,
			$order = 'DESC', $limit = 1, $firstId = '', $filters = null, $date_min = 0) {
		if (!$state) {
			$state = FreshRSS_Entry::STATE_ALL;
		}
		$where = '';
		$joinFeed = false;
		$values = array();
		switch ($type) {
		case 'a':	//All PRIORITY_MAIN_STREAM
			$where .= 'f.priority > ' . FreshRSS_Feed::PRIORITY_NORMAL . ' ';
			break;
		case 'A':	//All except PRIORITY_ARCHIVED
			$where .= 'f.priority >= ' . FreshRSS_Feed::PRIORITY_NORMAL . ' ';
			break;
		case 's':	//Starred. Deprecated: use $state instead
			$where .= 'f.priority >= ' . FreshRSS_Feed::PRIORITY_NORMAL . ' ';
			$where .= 'AND e.is_favorite=1 ';
			break;
		case 'S':	//Starred
			$where .= 'e.is_favorite=1 ';
			break;
		case 'c':	//Category
			$where .= 'f.priority >= ' . FreshRSS_Feed::PRIORITY_NORMAL . ' ';
			$where .= 'AND f.category=? ';
			$values[] = intval($id);
			break;
		case 'f':	//Feed
			$where .= 'e.id_feed=? ';
			$values[] = intval($id);
			break;
		case 't':	//Tag (label)
			$where .= 'et.id_tag=? ';
			$values[] = intval($id);
			break;
		case 'T':	//Any tag (label)
			$where .= '1=1 ';
			break;
		case 'ST':	//Starred or tagged (label)
			$where .= 'e.is_favorite=1 OR EXISTS (SELECT et2.id_tag FROM `_entrytag` et2 WHERE et2.id_entry = e.id) ';
			break;
		default:
			throw new FreshRSS_EntriesGetter_Exception('Bad type in Entry->listByType: [' . $type . ']!');
		}

		list($searchValues, $search) = $this->sqlListEntriesWhere('e.', $filters, $state, $order, $firstId, $date_min);

		return array(array_merge($values, $searchValues),
			'SELECT '
			. ($type === 'T' ? 'DISTINCT ' : '')
			. 'e.id FROM `_entry` e '
			. 'INNER JOIN `_feed` f ON e.id_feed = f.id '
			. ($type === 't' || $type === 'T' ? 'INNER JOIN `_entrytag` et ON et.id_entry = e.id ' : '')
			. 'WHERE ' . $where
			. $search
			. 'ORDER BY e.id ' . $order
			. ($limit > 0 ? ' LIMIT ' . intval($limit) : ''));	//TODO: See http://explainextended.com/2009/10/23/mysql-order-by-limit-performance-late-row-lookups/
	}

	private function listWhereRaw($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL,
			$order = 'DESC', $limit = 1, $firstId = '', $filters = null, $date_min = 0) {
		list($values, $sql) = $this->sqlListWhere($type, $id, $state, $order, $limit, $firstId, $filters, $date_min);

		$sql = 'SELECT e0.id, e0.guid, e0.title, e0.author, '
			. (static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', e0.link, e0.date, e0.is_read, e0.is_favorite, e0.id_feed, e0.tags, e0.attributes '
			. 'FROM `_entry` e0 '
			. 'INNER JOIN ('
			. $sql
			. ') e2 ON e2.id=e0.id '
			. 'ORDER BY e0.id ' . $order;

		$stm = $this->pdo->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return $stm;
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->listWhereRaw($type, $id, $state, $order, $limit, $firstId, $filters, $date_min);
			}
			Minz_Log::error('SQL error listWhereRaw: ' . $info[2]);
			return false;
		}
	}

	public function listWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL,
			$order = 'DESC', $limit = 1, $firstId = '', $filters = null, $date_min = 0) {
		$stm = $this->listWhereRaw($type, $id, $state, $order, $limit, $firstId, $filters, $date_min);
		if ($stm) {
			while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
				yield FreshRSS_Entry::fromArray($row);
			}
		} else {
			yield false;
		}
	}

	/** @param array<string> $ids */
	public function listByIds(array $ids, string $order = 'DESC') {
		if (count($ids) < 1) {
			return;
		}
		if (count($ids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
			// Split a query with too many variables parameters
			$idsChunks = array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
			foreach ($idsChunks as $idsChunk) {
				foreach ($this->listByIds($idsChunk, $order) as $entry) {
					yield $entry;
				}
			}
			return;
		}

		$sql = 'SELECT id, guid, title, author, '
			. (static::isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', link, date, is_read, is_favorite, id_feed, tags, attributes '
			. 'FROM `_entry` '
			. 'WHERE id IN (' . str_repeat('?,', count($ids) - 1). '?) '
			. 'ORDER BY id ' . $order;

		$stm = $this->pdo->prepare($sql);
		$stm->execute($ids);
		while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
			yield FreshRSS_Entry::fromArray($row);
		}
	}

	/**
	 * For API
	 * @return array<string>|false
	 */
	public function listIdsWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL,
								 $order = 'DESC', $limit = 1, $firstId = '', $filters = null) {
		[$values, $sql] = $this->sqlListWhere($type, $id, $state, $order, $limit, $firstId, $filters);

		$stm = $this->pdo->prepare($sql);
		$stm->execute($values);

		return $stm->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
	}

	public function listHashForFeedGuids($id_feed, $guids) {
		$result = [];
		if (count($guids) < 1) {
			return $result;
		} elseif (count($guids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
			// Split a query with too many variables parameters
			$guidsChunks = array_chunk($guids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
			foreach ($guidsChunks as $guidsChunk) {
				$result += $this->listHashForFeedGuids($id_feed, $guidsChunk);
			}
			return $result;
		}
		$guids = array_unique($guids);
		$sql = 'SELECT guid, ' . static::sqlHexEncode('hash') .
			' AS hex_hash FROM `_entry` WHERE id_feed=? AND guid IN (' . str_repeat('?,', count($guids) - 1). '?)';
		$stm = $this->pdo->prepare($sql);
		$values = array($id_feed);
		$values = array_merge($values, $guids);
		if ($stm && $stm->execute($values)) {
			$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				$result[$row['guid']] = $row['hex_hash'];
			}
			return $result;
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->listHashForFeedGuids($id_feed, $guids);
			}
			Minz_Log::error('SQL error listHashForFeedGuids: ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
				. ' while querying feed ' . $id_feed);
			return false;
		}
	}

	/**
	 * @param int $id_feed
	 * @param array<string> $guids
	 * @param int $mtime
	 * @return int|false The number of affected feeds, or false if error
	 */
	public function updateLastSeen($id_feed, $guids, $mtime = 0) {
		if (count($guids) < 1) {
			return 0;
		} elseif (count($guids) > FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER) {
			// Split a query with too many variables parameters
			$affected = 0;
			$guidsChunks = array_chunk($guids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER);
			foreach ($guidsChunks as $guidsChunk) {
				$affected += $this->updateLastSeen($id_feed, $guidsChunk, $mtime);
			}
			return $affected;
		}
		$sql = 'UPDATE `_entry` SET `lastSeen`=? WHERE id_feed=? AND guid IN (' . str_repeat('?,', count($guids) - 1). '?)';
		$stm = $this->pdo->prepare($sql);
		if ($mtime <= 0) {
			$mtime = time();
		}
		$values = array($mtime, $id_feed);
		$values = array_merge($values, $guids);
		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? $this->pdo->errorInfo() : $stm->errorInfo();
			if ($this->autoUpdateDb($info)) {
				return $this->updateLastSeen($id_feed, $guids);
			}
			Minz_Log::error('SQL error updateLastSeen: ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
				. ' while updating feed ' . $id_feed);
			return false;
		}
	}

	public function countUnreadRead() {
		$sql = 'SELECT COUNT(e.id) AS count FROM `_entry` e INNER JOIN `_feed` f ON e.id_feed=f.id WHERE f.priority > 0'
			. ' UNION SELECT COUNT(e.id) AS count FROM `_entry` e INNER JOIN `_feed` f ON e.id_feed=f.id WHERE f.priority > 0 AND e.is_read=0';
		$stm = $this->pdo->query($sql);
		if ($stm === false) {
			return false;
		}
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		rsort($res);
		$all = empty($res[0]) ? 0 : intval($res[0]);
		$unread = empty($res[1]) ? 0 : intval($res[1]);
		return array('all' => $all, 'unread' => $unread, 'read' => $all - $unread);
	}

	public function count($minPriority = null) {
		$sql = 'SELECT COUNT(e.id) AS count FROM `_entry` e';
		if ($minPriority !== null) {
			$sql .= ' INNER JOIN `_feed` f ON e.id_feed=f.id';
			$sql .= ' WHERE f.priority > ' . intval($minPriority);
		}
		$stm = $this->pdo->query($sql);
		if ($stm == false) {
			return false;
		}
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return isset($res[0]) ? intval($res[0]) : 0;
	}

	public function countNotRead($minPriority = null) {
		$sql = 'SELECT COUNT(e.id) AS count FROM `_entry` e';
		if ($minPriority !== null) {
			$sql .= ' INNER JOIN `_feed` f ON e.id_feed=f.id';
		}
		$sql .= ' WHERE e.is_read=0';
		if ($minPriority !== null) {
			$sql .= ' AND f.priority > ' . intval($minPriority);
		}
		$stm = $this->pdo->query($sql);
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return isset($res[0]) ? intval($res[0]) : 0;
	}

	public function countUnreadReadFavorites() {
		$sql = <<<'SQL'
SELECT c FROM (
	SELECT COUNT(e1.id) AS c, 1 AS o
		 FROM `_entry` AS e1
		 JOIN `_feed` AS f1 ON e1.id_feed = f1.id
		WHERE e1.is_favorite = 1
		  AND f1.priority >= :priority_normal1
	UNION
	SELECT COUNT(e2.id) AS c, 2 AS o
		 FROM `_entry` AS e2
		 JOIN `_feed` AS f2 ON e2.id_feed = f2.id
		WHERE e2.is_favorite = 1
		  AND e2.is_read = 0
		  AND f2.priority >= :priority_normal2
	) u
ORDER BY o
SQL;
		$stm = $this->pdo->prepare($sql);
		if (!$stm) {
			Minz_Log::error('SQL error in ' . __method__ . ' ' . json_encode($this->pdo->errorInfo()));
			return false;
		}
		//Binding a value more than once is not standard and does not work with native prepared statements (e.g. MySQL) https://bugs.php.net/bug.php?id=40417
		$stm->bindValue(':priority_normal1', FreshRSS_Feed::PRIORITY_NORMAL, PDO::PARAM_INT);
		$stm->bindValue(':priority_normal2', FreshRSS_Feed::PRIORITY_NORMAL, PDO::PARAM_INT);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		rsort($res);
		$all = empty($res[0]) ? 0 : intval($res[0]);
		$unread = empty($res[1]) ? 0 : intval($res[1]);
		return array('all' => $all, 'unread' => $unread, 'read' => $all - $unread);
	}
}
