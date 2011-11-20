<?php

defined('COT_CODE') or die('Wrong URL.');

/**
 * Basic ORM for Cotonti
 * 
 * Model classes should extend SimpleORM to inherit its methods 
 * and must specify $class_name, $table_name and $columns
 * 
 * @package Cotonti
 * @version 1.0
 * @author Gert Hengeveld
 * @copyright (c) Cotonti Team 2011
 * @license BSD
 */
abstract class SimpleORM
{
	protected $class_name = '';
	protected $table_name = '';
	protected $columns = array();
	protected $data = array();

	/**
	 * Include reference to $db for easy access in methods.
	 * Object data can be passed 
	 * 
	 * @param type $data 
	 */
	public function __construct($data = array())
	{
		global $db;
		$this->db = $db;
		$this->data = $data;
		$this->class_name = get_class($this);
	}

	/**
	 * Returns table name including prefix.
	 *
	 * @return type 
	 */
	protected function tableName()
	{
		global $db_x;
		return $db_x.$this->table_name;
	}

	/**
	 * Returns primary key column name. Defaults to 'id' if none was set.
	 *
	 * @return string
	 */
	protected function primaryKey()
	{
		foreach ($this->columns as $column => $info)
		{
			if ($info['primary_key']) return $column;
		}
		return 'id';
	}

	/**
	 * Getter and setter for object data
	 *
	 * @param string $key Optional key to fetch a single value
	 * @param mixed $value Optional value to set
	 * @return mixed Array of key->value pairs or a string value if $key was provided 
	 */
	public function data($key = null, $value = null)
	{
		if ($key !== null && $value !== null)
		{
			if (array_key_exists($key, $this->columns) && !$this->columns[$key]['locked'])
			{
				$this->data[$key] = $value;
				return true;
			}
			return false;
		}
		else
		{
			if ($key !== null)
			{
				if (array_key_exists($key, $this->columns) && !$this->columns[$key]['hidden'])
				{
					return $this->data[$key];
				}
			}
			else
			{
				$data = array();
				foreach ($this->columns as $key => $val)
				{
					if ($this->columns[$key]['hidden']) continue;
					$data[$key] = $this->data[$key];
				}
				return $data;
			}
		}
	}

	public function columns($include_locked = false, $include_hidden = false)
	{
		$cols = array();
		foreach ($this->columns as $key => $val)
		{
			if (!$include_hidden && $this->columns[$key]['hidden']) continue;
			if (!$include_locked && $this->columns[$key]['locked']) continue;
			$cols[$key] = $this->columns[$key];
		}
		return $cols;
	}

	/**
	 * Retrieve all existing objects from database
	 *
	 * @param mixed $conditions Array of SQL WHERE conditions or a single
	 *  condition as a string
	 * @param int $limit Maximum number of returned objects
	 * @param int $offset Offset from where to begin returning objects
	 * @param string $order Column name to order on
	 * @param string $way Order way 'ASC' or 'DESC'
	 * @return array
	 */
	public static function find($conditions, $limit = 0, $offset = 0, $order = '', $way = 'ASC')
	{
		SimpleORM::checkPHPVersion();
		$obj = new static();
		return $obj->fetch($conditions, $limit, $offset, $order, $way);
	}

	/**
	 * Retrieve all existing objects from database
	 *
	 * @param mixed $pk Primary key
	 * @return array
	 */
	public static function findAll($limit = 0, $offset = 0, $order = '', $way = 'ASC')
	{
		SimpleORM::checkPHPVersion();
		$obj = new static();
		return $obj->fetch(array(), $limit, $offset, $order, $way);
	}

	/**
	 * Retrieve existing object from database by primary key
	 *
	 * @param mixed $pk Primary key
	 * @return object
	 */
	public static function findByPk($pk)
	{
		SimpleORM::checkPHPVersion();
		$obj = new static();
		$res = $obj->fetch("{$obj->primaryKey()} = '$pk'", 1);
		return ($res) ? $res[0] : null;
	}

	/**
	 * Get all objects from the database matching given conditions
	 *
	 * @param mixed $conditions Array of SQL WHERE conditions or a single
	 *  condition as a string
	 * @param int $limit Maximum number of returned records or 0 for unlimited
	 * @param int $offset Return records starting from offset (requires $limit > 0)
	 * @return array List of objects matching conditions or null
	 */
	protected function fetch($conditions = array(), $limit = 0, $offset = 0, $order = '', $way = 'DESC')
	{
		$objects = array();
		$params = array();
		if (!is_array($conditions)) $conditions = array($conditions);
		if (count($conditions) > 0)
		{
			$where = array();
			foreach ($conditions as $condition)
			{
				$parts = array();
				preg_match_all('/(.+?)([<>= ]+)(.+)/', $condition, $parts);
				$column = trim($parts[1][0]);
				$operator = trim($parts[2][0]);
				$value = trim(trim($parts[3][0]), '\'"`');
				if ($column && $operator && $value)
				{
					$where[] = "$column $operator :$column";
					if (intval($value) == $value) $value = intval($value);
					$params[$column] = $value;
				}
			}
			$where = 'WHERE '.implode(' AND ', $where);
		}
		$order = ($order) ? "ORDER BY $order $way" : '';
		$limit = ($limit) ? "LIMIT $offset, $limit" : '';
		$res = $this->db->query("
			SELECT * FROM ".$this->tableName()." $where $order $limit
		", $params);
		while ($row = $res->fetch(PDO::FETCH_ASSOC))
		{
			$obj = new $this->class_name();
			$obj->data = $row;
			$objects[] = $obj;
		}
		return (count($objects) > 0) ? $objects : null;
	}

	/**
	 * Reload object data from database
	 *
	 * @return bool
	 */
	protected function loadData()
	{
		if (!$this->data[$this->primaryKey()]) return false;
		$res = $this->db->query("
			SELECT *
			FROM ".$this->tableName()."
			WHERE ".$this->primaryKey()." = ?
			LIMIT 1
		", array($this->data[$this->primaryKey()]))->fetch(PDO::FETCH_ASSOC);

		if ($res)
		{
			$this->data = $res;
			return true;
		}
		return false;
	}

	/**
	 * Verifies query data to meet column rules
	 *
	 * @param array $data Query data
	 * @return bool 
	 */
	protected function validateData($data)
	{
		global $db_x;
		if (!is_array($data)) return;

		foreach ($data as $column => $value)
		{
			if (!isset($this->columns[$column]))
			{
				cot_message("Invalid column name: $column", 'error', $column);
				return FALSE;
			}
			if ($this->columns[$column]['locked'] || $this->columns[$column]['auto_increment'])
			{
				cot_message("Can't update locked or auto_increment column: $column", 'error', $column);
				return FALSE;
			}
			if (isset($this->columns[$column]['length']) && mb_strlen($value) > $this->columns[$column]['length'])
			{
				cot_message("Value for column '$column' exceeds maximum length", 'error', $column);
				return FALSE;
			}
			$typecheck_pass = TRUE;
			switch ($this->columns[$column]['type'])
			{
				case 'int':
					if (!is_int($value)) $typecheck_pass = FALSE;
					break;
				case 'char':
				case 'varchar':
				case 'text':
					if (!is_string($value)) $typecheck_pass = FALSE;
					break;
				case 'decimal':
				case 'float':
					if (!is_double($value) && !is_float($value)) $typecheck_pass = FALSE;
					break;
			}
			if (!$typecheck_pass)
			{
				cot_message("Invalid variable type for column '$column': ".gettype($value), 'error', $column);
				return FALSE;
			}
			if ($this->columns[$column]['foreign_key'])
			{
				$fk = explode(':', $this->columns[$column]['foreign_key']);
				if (count($fk) == 2 && fieldExists($fk[0], $fk[1]))
				{
					if ($this->db->query("SELECT `{$fk[1]}` FROM `$db_x{$fk[0]}` WHERE `{$fk[1]}` = ?", array($value))->rowCount() == 0)
					{
						cot_message("Foreign key check failed, no such record in table $db_x{$fk[0]} for column {$fk[1]} and value $value", 'error', $column);
						return FALSE;
					}
				}
			}
		}
		return TRUE;
	}

	/**
	 * Prepare query data for usage
	 *
	 * @param array $data Query data as column => value pairs
	 * @param string $for 'insert' or 'update'
	 * @return array Prepared query data
	 */
	protected function prepData($data, $for)
	{
		global $sys;
		foreach ($this->columns as $column => $rules)
		{
			if ($for == 'insert' && isset($rules['on_insert']) && !isset($data[$column]))
			{
				if (is_string($rules['on_insert']))
					$rules['on_insert'] = str_replace('NOW()', $sys['now'], $rules['on_insert']);
				$data[$column] = $rules['on_insert'];
			}
			if ($for == 'update' && isset($rules['on_update']) && !isset($data[$column]))
			{
				if (is_string($rules['on_update']))
					$rules['on_update'] = str_replace('NOW()', $sys['now'], $rules['on_update']);
				$data[$column] = $rules['on_update'];
			}
		}
		return $data;
	}

	/**
	 * Saves object to database
	 * 
	 * @param string $action Allow only a specific action, 'update' or 'insert'
	 * @return string 'updated' or 'added'
	 */
	public function save($action = null)
	{
		global $usr;
		cot_block($usr['auth_write']);
		if ($this->data[$this->primaryKey()] && static::findByPk($this->data[$this->primaryKey()]))
		{
			cot_block($usr['isadmin']);
			if ($action && $action != 'update')
				return;
			if ($this->update(
					$this->data, $this->primaryKey().' = ?', array($this->data[$this->primaryKey()])
				) !== FALSE)
				return 'updated';
		}
		elseif (!$action || $action == 'insert')
		{
			if ($this->insert($this->data))
			{
				$this->data[$this->primaryKey()] = $this->db->lastInsertId();
				return 'added';
			}
		}
	}

	/**
	 * Create new object in database
	 *
	 * @param array $data Associative or 2D array containing data for insertion.
	 * @return int The number of affected records
	 */
	protected function insert($data)
	{
		return $this->db->insert($this->tableName(), $this->prepData($data, 'insert'));
	}

	/**
	 * Update object in database
	 *
	 * @param array $data Associative array containing data for update
	 * @param string $condition Body of SQL WHERE clause
	 * @param array $params Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
	 * @return int The number of affected records or FALSE on error
	 */
	protected function update($data, $condition, $params = array())
	{
		return $this->db->update($this->tableName(), $this->prepData($data, 'update'), $condition, $params);
	}

	/**
	 * Remove object from database
	 *
	 * @param string $condition Body of WHERE clause
	 * @param array $params Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
	 * @return int Number of records removed on success or FALSE on error
	 */
	public function delete($condition, $params = array())
	{
		return $this->db->delete($this->tableName(), $condition, $params);
	}

	/**
	 * Die if PHP version is lower than 5.3.
	 * 
	 * PHP 5.3 is required for late static bindings used in finder methods.
	 * SimpleORM can still be used with older versions, but finder methods 
	 * must be circumvented by instantiating objects within the controller 
	 * instead of retrieving object instances through a finder. For example:
	 *   $obj = new MyClass();
	 *   $obj->loadData($pk);
	 * Instead of:
	 *   $obj = MyClass::findByPk($pk);
	 */
	public static function checkPHPVersion()
	{
		static $versioncompare = null;
		if ($versioncompare === null)
		{
			$versioncompare = version_compare(PHP_VERSION, '5.3.0');
		}
		if ($versioncompare == -1)
		{
			die('PHP version 5.3 or higher is required.');
		}
	}

	/**
	 * Create the table
	 *
	 * @return bool
	 */
	public static function createTable()
	{
		global $db;
		$obj = new static();
		$table = $obj->tableName();
		$pk = $obj->primaryKey();
		$cols = $obj->columns(true, true);

		$indexes = array();
		$columns = array();
		foreach ($cols as $name => $params)
		{
			$props = array();
			$params['attributes'] !== NULL && $props[] = $params['attributes'];
			$props[] = ($params['null']) ? 'NULL' : 'NOT NULL';
			$params['default_value'] !== NULL && $props[] = "DEFAULT '{$params['default_value']}'";
			$params['auto_increment'] && $props[] = 'AUTO_INCREMENT';
			$props = implode(' ', $props);

			$params['primary_key'] && $indexes[] = "PRIMARY KEY (`$name`)";
			$params['index'] && $indexes[] = "KEY `i_$name` (`$name`)";
			$params['unique'] && $indexes[] = "UNIQUE KEY `u_$name` (`$name`)";

			$type = strtoupper($params['type']);
			if ($params['length'])
				$type .= "({$params['length']})";
			$columns[] = "`$name` $type $props";
		}
		$columns = implode(', ', array_merge($columns, $indexes));

		return (bool) $db->query("
			CREATE TABLE IF NOT EXISTS `$table` ($columns)
			DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
		");
	}

	/**
	 * Drop the table
	 *
	 * @return bool
	 */
	public static function dropTable()
	{
		global $db;
		$obj = new static();
		return (bool) $db->query("
			DROP TABLE IF EXISTS `".$obj->tableName()."`
		");
	}
}

?>
