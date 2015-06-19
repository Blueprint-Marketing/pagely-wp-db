<?php
/**
 * WordPress DB drop-in replacement for stats collection and creating a secondary read/write stream fro testing
 */
class PagelyWpdb extends wpdb
{
	public $statsFile = '/tmp/db-stats.log';
	public $queryFile = '/tmp/db-queries.log';

	/**
	 * Internal function to perform the mysql_query() call.
	 *
	 * @since 3.9.0
	 *
	 * @access private
	 * @see wpdb::query()
	 *
	 * @param string $query The query to run.
	 */
	private function _do_query( $query )
	{
		$this->timer_start();

		$queryId = uniqid(substr(md5($query), 0, 8));
		$query = str_replace(array("\r\n","\r","\n"), " ", $query);
		file_put_contents($this->queryFile, "$queryId:\t$query\n", FILE_APPEND | LOCK_EX);

		if ( $this->use_mysqli ) {
			$this->result = @mysqli_query( $this->dbh, $query );
		} else {
			$this->result = @mysql_query( $query, $this->dbh );
		}
		$this->num_queries++;

		$time = round($this->timer_stop(), 5);
		$rows = 'na';
		$type = 'na';
		if (!is_bool($this->result))
		{
			$type = 's';
			if ( $this->use_mysqli ) {
				$rows = mysqli_num_rows($this->result);
			} else {
				$rows = mysql_num_rows($this->result);
			}
		}
		else
		{
			$type = 'u';
			if ( $this->use_mysqli ) {
				$rows = mysqli_affected_rows($this->dbh);
			} else {
				$rows = mysql_affected_rows($this->dbh);
			}
		}
		file_put_contents($this->statsFile, "$queryId:\t$time\t$type$rows\n", FILE_APPEND | LOCK_EX);
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->queries[] = array( $query, $time, $this->get_caller() );
		}
	}

	// _do_query is private so i have to copy/paste its caller to use my version, sigh
	/**
	 * Perform a MySQL database query, using current database connection.
	 *
	 * More information can be found on the codex page.
	 *
	 * @since 0.71
	 *
	 * @param string $query Database query
	 * @return int|false Number of rows affected/selected or false on error
	 */
	public function query( $query ) {
		if ( ! $this->ready ) {
			$this->check_current_query = true;
			return false;
		}

		/**
		 * Filter the database query.
		 *
		 * Some queries are made before the plugins have been loaded,
		 * and thus cannot be filtered with this method.
		 *
		 * @since 2.1.0
		 *
		 * @param string $query Database query.
		 */
		$query = apply_filters( 'query', $query );

		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// If we're writing to the database, make sure the query will write safely.
		if ( $this->check_current_query && ! $this->check_ascii( $query ) ) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );
			// strip_invalid_text_from_query() can perform queries, so we need
			// to flush again, just to make sure everything is clear.
			$this->flush();
			if ( $stripped_query !== $query ) {
				$this->insert_id = 0;
				return false;
			}
		}

		$this->check_current_query = true;

		// Keep track of the last query for debug..
		$this->last_query = $query;

		$this->_do_query( $query );

		// MySQL server has gone away, try to reconnect
		$mysql_errno = 0;
		if ( ! empty( $this->dbh ) ) {
			if ( $this->use_mysqli ) {
				$mysql_errno = mysqli_errno( $this->dbh );
			} else {
				$mysql_errno = mysql_errno( $this->dbh );
			}
		}

		if ( empty( $this->dbh ) || 2006 == $mysql_errno ) {
			if ( $this->check_connection() ) {
				$this->_do_query( $query );
			} else {
				$this->insert_id = 0;
				return false;
			}
		}

		// If there is an error then take note of it..
		if ( $this->use_mysqli ) {
			$this->last_error = mysqli_error( $this->dbh );
		} else {
			$this->last_error = mysql_error( $this->dbh );
		}

		if ( $this->last_error ) {
			// Clear insert_id on a subsequent failed insert.
			if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) )
				$this->insert_id = 0;

			$this->print_error();
			return false;
		}

		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			$return_val = $this->result;
		} elseif ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
			if ( $this->use_mysqli ) {
				$this->rows_affected = mysqli_affected_rows( $this->dbh );
			} else {
				$this->rows_affected = mysql_affected_rows( $this->dbh );
			}
			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				if ( $this->use_mysqli ) {
					$this->insert_id = mysqli_insert_id( $this->dbh );
				} else {
					$this->insert_id = mysql_insert_id( $this->dbh );
				}
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$num_rows = 0;
			if ( $this->use_mysqli && $this->result instanceof mysqli_result ) {
				while ( $row = @mysqli_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			} elseif ( is_resource( $this->result ) ) {
				while ( $row = @mysql_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			}

			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		return $return_val;
	}


}

global $wpdb;
$wpdb = new PagelyWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
