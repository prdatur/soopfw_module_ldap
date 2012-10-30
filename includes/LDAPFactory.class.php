<?php

/**
 * Provides a ldap factory
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 */
class LDAPFactory extends Object
{

	/**
	 * Create a LDAPInterface instance and return it or return false
	 *
	 * @param string $server
	 *   the servername which must be configured over the ldap manager
	 * @param string $username
	 *   if provided this will override the server username
	 *   (optional, default = '')
	 * @param string $password
	 *   if provided this will override the server password
	 *   (optional, default = '')
	 *
	 * @return LDAPInterface The ldap client or false on error.
	 */
	public static function create_instance($server, $username = '', $password = '') {
		static $cache = array();

		//Get the couchdb server object and try to load it
		if(preg_match("/^[0-9]+$/", $server)) {
			$object = new LdapServerObj($server);
		}
		else {
			$object = new LdapServerObj();
			$object->db_filter->add_where('server', $server);
			$object->load();
		}

		//Check if server exist
		if (!$object->load_success()) {
			return false;
		}

		if(!isset($cache[$object->id])) {
			//Get all server configurations
			$options = $object->get_values(true);

			//Extend our options with provided method arguments if they are not empty
			if (!empty($username)) {
				$options['admindn'] = $username;
			}
			if (!empty($password)) {
				$options['password'] = $password;
			}

			//Init the client
			$client = new LDAPInterface($options['host'], $options['basedn'], $options['admindn'], $options['password'], $options['port']);
			$cache[$object->id] = false;
			if($client->connect()) {
				$cache[$object->id] = $client;
			}
		}

		return $cache[$object->id];
	}

	/**
	 * Returns an array with all configured servers.
	 *
	 * @param boolean $include_empty
	 *   If set to true it will have an empty entry with 'none' as key and 'None' as value at the
	 *   first array index.
	 *   Usefull for configurations (optional, default = false)
	 *
	 * @return array The data.
	 */
	public static function get_all_instances($include_empty = false) {
		$filter = DatabaseFilter::create(LdapServerObj::TABLE)
			->add_column('id')
			->add_column('server');

		if ($include_empty === true) {
			return array('none' => t('None')) + $filter->select_all('id', true);
		}
		return $filter->select_all('id', true);
	}

}