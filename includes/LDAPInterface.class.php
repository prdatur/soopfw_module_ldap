<?php

/**
 * Provide an ldap interface which enable us to handle ldap data
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 */
class LDAPInterface
{

	/**
	 * The ldap base dn
	 *
	 * @var string
	 */
	public $basedn;

	/**
	 * Holds the current ldap connection resource
	 *
	 * @var resource
	 */
	private $connection;

	/**
	 * the ldap server
	 *
	 * @var string
	 */
	private $server;

	/**
	 * The admin dn
	 *
	 * @var string
	 */
	private $admindn;

	/**
	 * the password for the admin dn
	 * @var string
	 */
	private $password;

	/**
	 * the ldap port
	 *
	 * @var int
	 */
	private $port;

	/**
	 * if we should use tls
	 *
	 * @var boolean
	 */
	private $tls;

	/**
	 * constructor
	 *
	 * @param string $server
	 *   the server
	 * @param string $basedn
	 *   the base dn
	 * @param string $admindn
	 *   the admin dn
	 * @param string $password
	 *   the password
	 * @param int $port
	 *   the server port
	 *   (optional, default = 389)
	 */
	function __construct($server, $basedn, $admindn, $password, $port = 389) {
		$this->connection = null;
		$this->server = $server;
		$this->port = $port;
		$this->tls = false;
		$this->admindn = $admindn;
		$this->password = $password;
		$this->basedn = $basedn;
	}

	public function connect() {
		$this->disconnect();
		if (!$con = @ldap_connect($this->server, $this->port)) {
			return false;
		}

		@ldap_set_option($con, LDAP_OPT_PROTOCOL_VERSION, 3);
		@ldap_set_option($con, LDAP_OPT_REFERRALS, 0);
		// TLS encryption contributed by sfrancis@drupal.org
		if ($this->tls) {
			@ldap_get_option($con, LDAP_OPT_PROTOCOL_VERSION, $vers);
			if ($vers == -1 || $vers != 3 || !function_exists('ldap_start_tls') || !@ldap_start_tls($con)) {
				return false;
			}
		}
		$this->connection = $con;
		return @ldap_bind($this->connection, $this->admindn, $this->password);
	}

	public function disconnect() {
		if ($this->connection) {
			@ldap_unbind($this->connection);
			$this->connection = null;
		}
	}

	/**
	 * Search within the given base dn entries which have the given filter and return the given attributes for the found entries
	 *
	 * @param string $base_dn the dn where we start to search
	 * @param string $filter the filter string for ldap
	 * @param array $attributes a set of attributes which we only want back from the result, empty array will return all fields (optional, default = array())
	 * @return array return all found entries
	 */
	public function search($base_dn, $filter, $attributes = array()) {
		$ret = array();

		// For the ActiveDirectory the '\,' should be replaced by the '\\,' in the search filter.
		$filter = preg_replace('/\\\,/', '\\\\\,', $filter);

		//Execute the search
		$x = @ldap_search($this->connection, $base_dn, $filter, $attributes, 0, 100);

		//If we got some entries, set up the return array
		if ($x && @ldap_count_entries($this->connection, $x)) {
			$ret = @ldap_get_entries($this->connection, $x);
		}

		//Remove not wanted array keys like the count key and number keys
		$this->remove_not_wanted_keys($ret);

		/**
		 * Loop through all results to remove the numeric index value,
		 * also we remove single value arrays to single value strings
		 * be aware, if the schema normaly allow multi values and only one value
		 * is left, it will have just a string, not the array as expected
		 */
		foreach ($ret AS $k => &$result) {
			foreach ($result AS $k => &$v) {
				if ((int)$k === $k || (!is_array($v) && $k != 'dn')) {
					unset($result[$k]);
					continue;
				}
				if (is_array($v) && count($v) == 1) {
					$v = reset($v);
				}
			}
		}

		return $ret;
	}

	/**
	 * Search within the given base dn entries which have the given filter and return the given attributes for the found entries
	 * With this function we only need to add the levels after the global basedn which we had configured.
	 *
	 * So for example if a dn is cn=foo,dc=people,dc=domain,dc=tld and we configured basedn as dc=domain,dc=tld we just need to provide cn=foo,dc=people
	 *
	 * @param string $base_dn the dn where we start to search
	 * @param string $filter the filter string for ldap
	 * @param array $attributes a set of attributes which we only want back from the result, empty array will return all fields (optional, default = array())
	 * @return array return all found entries
	 */
	public function search_base_dn($base_dn, $filter, $attributes = array()) {
		return $this->search($base_dn.",".$this->basedn, $filter, $attributes);
	}

	/**
	 * Retrieve all attributes for a given dn
	 *
	 * WARNING! WARNING! WARNING!
	 * This function returns its entries with lowercase attribute names.
	 * Don't blame me, blame PHP's own ldap_get_entries()
	 * @param string $dn the dn
	 * @return array the values in lowercase keys
	 */
	public function retrieve_attributes($dn) {
		//init the retrieve
		$result = @ldap_read($this->connection, $dn, 'objectClass=*');

		//Get the attributes
		$entries = @ldap_get_entries($this->connection, $result);

		//take just the first one
		$results = $entries[0];

		//nothing found
		if (empty($results)) {
			return array();
		}

		/**
		 * Loop through all results to remove the numeric index value,
		 * also we remove single value arrays to single value strings
		 * be aware, if the schema normaly allow multi values and only one value
		 * is left, it will have just a string, not the array as expected
		 */
		foreach ($results AS $k => &$v) {
			if ((int)$k === $k || !is_array($v)) {
				unset($results[$k]);
				continue;
			}
			if ($v['count'] == 1) {
				$v = $v[0];
				continue;
			}
			unset($v['count']);
		}
		return $results;
	}

	/**
	 * Retrieve a single attribute from the given dn
	 *
	 * @param string $dn the dn
	 * @param string $attrname the attribute name
	 * @return string the value, if it was an array, only first value will be returned, if attribute name does not exists, return null
	 */
	public function retrieve_attribute($dn, $attrname) {
		$entries = $this->retrieve_attributes($dn);
		if (!isset($entries[strtolower($attrname)])) {
			return null;
		}
		return is_array($entries[strtolower($attrname)]) ? $entries[strtolower($attrname)][0] : $entries[strtolower($attrname)];
	}

	/**
	 * Retrieve an array attribute from the given dn
	 *
	 * @param string $dn the dn
	 * @param string $attrname the attribute name
	 * @return array the value, if it was only one value it will transformed to an array, if attribute name does not exists, return null
	 */
	public function retrieve_attribute_array($dn, $attrname) {
		$entries = $this->retrieve_attributes($dn);

		//If attribute does not exist return null
		if (empty($entries[strtolower($attrname)])) {
			return array();
		}

		//Get the value
		$retrieved = $entries[strtolower($attrname)];
		if (!empty($retrieved)) {
			//Transform non array to an array
			if (!is_array($retrieved)) {
				$retrieved = array($retrieved);
			}
		}
		//Prohibit wrong returning values
		else {
			$retrieved = array();
		}

		//Just add "real" values, skip the count key
		$result = array();
		foreach ($retrieved as $key => $value) {
			if ($key !== 'count') {
				$result[] = $value;
			}
		}
		return $result;
	}

	/**
	 * Writes the given attributes to the dn
	 * if a value is empty for an attribute, it will be handled as deletion so it will try
	 * to delete the attribute
	 * @param string $dn the dn to write
	 * @param array $attributes the attributes to write
	 * @return boolean, true on success, else false
	 */
	public function write_attributes($dn, Array $attributes) {

		//Loop through all provided new attributes
		foreach ($attributes as $key => $cur_val) {
			//Check if we should delete the attribute
			if ($cur_val == '') {
				//Unset the deletion from current attributes array
				unset($attributes[$key]);

				//Get the old entry
				$old_value = $this->retrieve_attribute($dn, $key);
				//If the old value is not found we can not delete it, its not present
				if (isset($old_value)) {
					//Delelete the attribute
					$v = array($key => $old_value);
					ldap_mod_del($this->connection, $dn, $v);
				}
			}
			//If we provided an array we extend our modify attributes array to the multi value
			if (is_array($cur_val)) {

				foreach ($cur_val as $mv_key => $mv_cur_val) {
					//the value is empty, unset the multi value
					if ($mv_cur_val == '') {
						unset($attributes[$key][$mv_key]);
					}
				}
			}
		}
		return @ldap_modify($this->connection, $dn, $attributes);
	}

	/**
	 * Adds a value to the specified multi value attribute
	 *
	 * @param string $dn the dn
	 * @param string $attribute the attribute
	 * @param mixed $value the value
	 * @return boolean true on success, else false
	 */
	public function add_attribute($dn, $attribute, $value) {
		$values = $this->retrieve_attribute_array($dn, $attribute);
		//If value already present to do nothing
		if (in_array($value, $values)) {
			return true;
		}
		//Add value
		$values[] = $value;

		//Escape all values
		Ldap::escape_recursive($values);
		return $this->write_attributes($dn, array($attribute => $values));
	}

	/**
	 * Creates an ldap entry
	 *
	 * @param string $dn the new dn
	 * @param array $attributes the attributes for the new entry
	 * @return boolean true on success, else false
	 */
	public function create_entry($dn, $attributes) {

		//Escape all values
		Ldap::escape_recursive($attributes);

		//Unset empty values
		foreach ($attributes AS $k => &$v) {
			if (empty($v)) {
				unset($attributes[$k]);
			}
		}

		//strib slashes on the userpassword because it should not be escaped
		if (isset($attributes['userPassword'])) {
			$attributes['userPassword'] = stripslashes($attributes['userPassword']);
		}

		//create the entry
		return @ldap_add($this->connection, $dn, $attributes);
	}

	/**
	 * Checks if a given dn entry exist or not
	 *
	 * @param string $dn the dn
	 * @return boolean true if exist, else false
	 */
	public function entry_exists($dn) {
		return (@ldap_read($this->connection, $dn, 'objectClass=*') !== false);
	}

	/**
	 * Renames a dn
	 *
	 * @param string $dn The distinguished name of an LDAP entity.
	 * @param string $newrdn The new RDN.
	 * @param string $newparent The new parent/superior entry. (optional, default = null)
	 * @param bool $deleteoldrdn If true the old RDN value(s) is removed, else the old RDN value(s) is retained as non-distinguished values of the entry. (optional, default = false)
	 * @return boolean true on success, else false
	 */
	public function rename_entry($dn, $newrdn, $newparent = null, $deleteoldrdn = false) {
		return @ldap_rename($this->connection, $dn, $newrdn, $newparent, $deleteoldrdn);
	}

	/**
	 * Delete an entry, if recursive is set to false and the entry has childs the delete will fail.
	 *
	 * @param string $dn the dn to delete
	 * @param boolean $recursive if we want to delete the entry recursive
	 * @return boolean true on success, else false
	 */
	public function delete_entry($dn, $recursive = false) {

		//Check if we only should delete a single entry
		if ($recursive == false) {
			return @ldap_delete($this->connection, $dn);
		}
		else {
			//searching for sub entries
			$sr = ldap_list($this->connection, $dn, "ObjectClass=*", array(""));
			$info = ldap_get_entries($this->connection, $sr);

			//Loop through all found sub entries
			for ($i = 0; $i < $info['count']; $i++) {
				//deleting recursively sub entries
				$result = $this->delete_entry($info[$i]['dn'], $recursive);
				if (!$result) {
					//return result code, if delete fails
					return($result);
				}
			}
			//Delete the entry
			return($this->delete_entry($dn));
		}
	}

	/**
	 * Deletes an attribute from the given dn
	 *
	 * This function is used by other modules to delete attributes once they are
	 * moved to profiles cause ldap_mod_del does not delete facsimileTelephoneNumber if
	 * attribute value to delete is passed to the function.
	 * OpenLDAP as per RFC 2252 doesn't have equality matching for facsimileTelephoneNumber
	 * http://bugs.php.net/bug.php?id=7168
	 *
	 * @param string $dn the dn
	 * @param string $attribute the attribute
	 * @return boolean true on success, else false
	 */
	public function delete_attribute($dn, $attribute) {
		return @ldap_mod_del($this->connection, $dn, array($attribute => array()));
	}

	/**
	 * Removes a given value from given attribute within the given dn
	 *
	 * @param String $dn the dn to edit
	 * @param String $attrname the attribute where we want to remove the value
	 * @param String $value the value which we want to remove
	 * @return boolean true on success, else false
	 */
	public function remove_attribute_value($dn, $attrname, $value) {
		$values = $this->retrieve_attribute_array($dn, $attrname);
		$values_write = array();
		foreach ($values AS $k => $val) {
			if ($value != $val) {
				$values_write[] = $values[$k];
			}
		}
		return $this->write_attributes($dn, array(
				$attrname => $values_write
			));
	}

	/**
	 * Removes integer index keys and count entries
	 *
	 * @param array &$array the array to be proccessed
	 */
	private function remove_not_wanted_keys(&$array) {
		foreach ($array AS $k => &$v) {
			if ((int)$k == $k && !is_array($v) && isset($array[$v])) {
				unset($array[$k]);
			}
			if ($k."" == "count" && !is_array($v)) {
				unset($array[$k]);
			}
			else if (is_array($v)) {
				$this->remove_not_wanted_keys($v);
			}
		}
	}

}