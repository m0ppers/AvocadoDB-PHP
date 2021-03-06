<?php

/**
 * ArangoDB PHP client: result set cursor
 * 
 * @package ArangoDbPhpClient
 * @author Jan Steemann
 * @copyright Copyright 2012, triagens GmbH, Cologne, Germany
 */

namespace triagens\ArangoDb;

/**
 * Provides access to the results of a read-only statement
 * The cursor might not contain all results in the beginning.
 * If the result set is too big to be transferred in one go, the
 * cursor might issue additional HTTP requests to fetch the
 * remaining results from the server.
 *
 * @package ArangoDbPhpClient
 */
class Cursor implements \Iterator {
  /**
   * The connection object
   * @var Connection
   */
  private $_connection;

  /**
   * Cursor options
   * @var array
   */
  private $_options;
  
  /**
   * The result set
   * @var array
   */
  private $_result;
  
  /**
   * "has more" indicator - if true, the server has more results
   * @var bool
   */
  private $_hasMore;
  
  /**
   * cursor id - might be NULL if cursor does not have an id
   * @var mixed
   */
  private $_id;

  /**
   * current position in result set iteration (zero-based)
   * @var int
   */
  private $_position;
  
  /**
   * total length of result set (in number of documents)
   * @var int
   */
  private $_length;


  /**
   * result entry for cursor id
   */
  const ENTRY_ID        = 'id';
  
  /**
   * result entry for "hasMore" flag
   */
  const ENTRY_HASMORE   = 'hasMore';
  
  /**
   * result entry for result documents
   */
  const ENTRY_RESULT    = 'result';
  
  /**
   * sanitize option entry
   */
  const ENTRY_SANITIZE  = 'sanitize';

  /**
   * Initialise the cursor with the first results and some metadata
   *
   * @param Connection $connection - connection to be used
   * @param array $data - initial result data as returned by the server
   * @param array $options - cursor options
   * @return void 
   */
  public function __construct(Connection $connection, array $data, array $options) {
    $this->_connection = $connection;

    $this->_id = NULL;
    if (isset($data[self::ENTRY_ID])) {
      $this->_id = $data[self::ENTRY_ID];
    }

    // attribute must be there
    assert(isset($data[self::ENTRY_HASMORE]));
    $this->_hasMore = (bool) $data[self::ENTRY_HASMORE];

    $this->_options = $options;
    $this->_result = array();
    $this->addDocumentsFromArray((array) $data[self::ENTRY_RESULT]);
    $this->updateLength();

    $this->rewind();
  }

  private function addDocumentsFromArray(array $data)
  {
    foreach ($this->sanitize($data) as $row) {
      $this->_result[] = Document::createFromArray($row);
    }
  }
  
  /**
   * Explicitly delete the cursor
   * This might issue an HTTP DELETE request to inform the server about
   * the deletion.
   *
   * @throws Exception
   * @return bool - true if the server acknowledged the deletion request, false otherwise
   */
  public function delete() {
    if ($this->_id) {
      try {
        $this->_connection->delete(Urls::URL_CURSOR . '/' . $this->_id);
        return true;
      } 
      catch (Exception $e) {
      }
    }

    return false;
  }
  
  /**
   * Get the total number of results in the cursor
   * This might issue additional HTTP requests to fetch any outstanding
   * results from the server
   *
   * @throws Exception
   * @return int - total number of results
   */
  public function getCount() {
    while ($this->_hasMore) {
      $this->fetchOutstanding();
    }

    return $this->_length;
  }
  
  /**
   * Get all results as an array 
   * This might issue additional HTTP requests to fetch any outstanding
   * results from the server
   *
   * @throws Exception
   * @return array - an array of all results
   */
  public function getAll() {
    while ($this->_hasMore) {
      $this->fetchOutstanding();
    }

    return $this->_result;
  }
  
  /**
   * Rewind the cursor, necessary for Iterator
   *
   * @return void
   */
  public function rewind() {
    $this->_position = 0;
  }

  /**
   * Return the current result row, necessary for Iterator
   *
   * @return array - the current result row as an assoc array
   */
  public function current() {
    return $this->_result[$this->_position];
  }
  
  /**
   * Return the index of the current result row, necessary for Iterator
   *
   * @return int - the current result row index
   */
  public function key() {
    return $this->_position;
  }

  /**
   * Advance the cursor, necessary for Iterator
   *
   * @return void
   */
  public function next() {
    ++$this->_position;
  }
  
  /**
   * Check if cursor can be advanced further, necessary for Iterator
   * This might issue additional HTTP requests to fetch any outstanding
   * results from the server
   *
   * @throws Exception
   * @return bool - true if the cursor can be advanced further, false if cursor is at end
   */
  public function valid() {
    if ($this->_position <= $this->_length -1) {
      // we have more results than the current position is
      return true;
    }

    if (!$this->_hasMore || !$this->_id) {
      // we don't have more results, but the cursor is exhausted
      return false;
    }
  
    // need to fetch additional results from the server
    $this->fetchOutstanding();

    return ($this->_position <= $this->_length - 1);
  }

  /**
   * Sanitize the result set rows
   * This will remove the _id and _rev attributes from the results if the
   * "sanitize" option is set
   *
   * @param array $rows - array of rows to be sanitized
   * @return array - sanitized rows
   */
  private function sanitize(array $rows) {
    if (isset($this->_options[self::ENTRY_SANITIZE]) and $this->_options[self::ENTRY_SANITIZE]) {
      foreach ($rows as $key=>$value) {
        unset($rows[$key][Document::ENTRY_ID]);
        unset($rows[$key][Document::ENTRY_REV]);
      }
    }
    return $rows;
  }
      
  /**
   * Fetch outstanding results from the server
   *
   * @throws Exception
   * @return void
   */
  private function fetchOutstanding() {
    // continuation
    $response = $this->_connection->put(Urls::URL_CURSOR . "/" . $this->_id, '');
    $data = $response->getJson();

    $this->_hasMore = (bool) $data[self::ENTRY_HASMORE];
    $this->addDocumentsFromArray($data[self::ENTRY_RESULT]);

    if (!$this->_hasMore) {
      // we have fetch the complete result set and can unset the id now 
      $this->_id = NULL;
    }

    $this->updateLength();
  }

  /**
   * Set the length of the (fetched) result set
   *
   * @return void
   */
  private function updateLength() {
    $this->_length = count($this->_result); 
  }
}
