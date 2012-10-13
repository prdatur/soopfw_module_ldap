<?php

/**
 * Provides a ldap login handler.
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @package modules.ldap.includes
 */
class LDAPLoginHandler extends Object implements LoginHandler
{

	/**
	 * Log the current user out, if $time is higher than 0 than the request_redirection will be used,
	 * else it will a direct header location event.
	 *
	 * @param int $time
	 *   set the redirection timeout after logout (optional, default = 0)
	 * @param boolean $justreturn
	 *   if we just want to logout the current user with no redirect (optional, default = false)
	 */
	public function logout($time = 0, $justreturn = false) {


		//If we do only want to log the user out and do not want to redirect after, return
		if ($justreturn) {
			return;
		}

		//if we have a time, request the redirection, else redirect with header location
		if ($time > 0) {
			$this->core->request_redirect("/", $time);
		}
		else {
			$this->core->location("/");
		}
	}

	/**
	 * Returns the login url for this login handler.
	 *
	 * @return string the login url
	 */
	public function get_login_url() {
		return '/user/login.html';
	}

	/**
	 * Returns the logout url for this login handler.
	 *
	 * @return string the logout url
	 */
	public function get_logout_url() {
		return '/user/logout.html';
	}

	/**
	 * Returns the profile url for this login handler.
	 *
	 * @param UserObj $user_obj
	 *   the user object, if provided it will get the profile url for this account (optional, default = null)
	 *
	 * @return string the profile url
	 */
	public function get_profile_url($user_obj = null) {
		$profile_url = '/user/edit/@userid';
		if (!is_null($user_obj)) {
			$profile_url = str_replace('@userid', $user_obj->user_id, $profile_url);
		}
		return $profile_url;
	}

	/**
	 * Returns all urls for this handler which are not allowed for redirecting after login.
	 *
	 * This is needed to prevent redirect loops.
	 *
	 * @return array all urls on which we can not redirect.
	 */
	public function get_handler_urls() {
		return array();
	}

	/**
	 * Checks if the user is logged in, if not we will redirect him to the login page
	 *
	 * @param boolean $force_not_loggedin force not logged in that the user will be redirected to the user login page
	 * @param boolean $need_direct_handler need this login handler as valid
	 * @return boolean true if normal behaviour should checked (Session::require_login which redirects if the is_logged_in param is set to false), false if the login handler handles this event
	 */
	public function require_login($force_not_loggedin = false, $need_direct_handler = false) {
		return true;
	}

	/**
	 * Check if the user is logged in and log the user in if a post was provided.
	 *
	 * @return boolean returns true on successfully login else false
	 */
	public function check_login() {

		//Initialize check variables, these variables will be ether filled from session or from post variables
		$check_user = "";

		$session_id = $this->session->get_session_id();

		//If we have NOT posted and session variables are NOT empty check the user with the current session
		if (!empty($session_id)) {
			//Load the session object for the current session
			$session_obj = new UserSessionObj($session_id);

			//Check if we found a valid session
			if ($session_obj->load_success() === false) {
				return false;
			}
			else {
				//Set the crrent user object
				$user_obj = new UserObj($session_obj->user_id);
				if(!$user_obj->load_success()) {
					return false;
				}
				return $this->session->validate_login($user_obj);
			}
		}
		//No session or login post found, we are on guest mode
		else {
			$check_user = 'guest';
		}

		return $this->validate_login($check_user, "");
	}

	/**
	 * This is called within the login page without posting something and is used for Single Sign On's like openID, shibboleth or Facebook.
	 * This is a direct check if the user is logged in without a need to provide credentials.
	 *
	 * @return boolean returns true on successfully login else false
	 */
	public function pre_validate_login() {
		return false;
	}

	/**
	 * Check if the given credentials are valid and if so setup a new session object
	 * or update the old one, also update the last login time.
	 *
	 * @param string $username
	 *   the username
	 * @param string $password
	 *   the crypted password
	 *
	 * @return boolean return true if provided credentials are valid, else false
	 */
	public function validate_login($username, $password) {

		// Do not all allow empty passwords.
		if (empty($password)) {
			return false;
		}
		
		// Get all enabled ldap authentication configs.
		$filter = DatabaseFilter::create(LdapAuthenticationConfigObj::TABLE)
			->add_column('id')
			->add_column('lookup_dn')
			->add_where('enable', 1);

		$user_obj = false;

		// Try each ldap server for valid login.
		foreach ($filter->select_all() AS $row) {

			// Get the auth dn.
			$userdn = str_replace('%username%', $username, $row['lookup_dn']);

			// Check the login.
			$ldap_interface = LDAPFactory::create_instance($row['id'], $userdn, $password);

			// If the ldap factory returns an empty value, the login bind did not worked so we need to check the next available server.
			if (empty($ldap_interface)) {
				continue;
			}

			// LDAP Login succeed. Check if we need to create a new user (first login).
			$user_obj = new UserObj();
			$user_obj->db_filter->add_where('username', $username);
			$user_obj->load();

			$address_id = 0;

			// Check if we need to create the user.
			if (!$user_obj->load_success()) {

				// User did not exist, create a new one.
				$user_obj->username = $username;
				$user_obj->password = 'LDAP_ACCOUNT';
				$user_obj->language = $this->core->current_language;
				$user_obj->registered = date(DB_DATETIME, TIME_NOW);
				$user_obj->last_login = date(DB_DATETIME, TIME_NOW);

				// Insert the data.
				if (!$user_obj->insert(false, false)) {
					$user_obj = new UserObj();
					continue;
				}
			}
			else {
				// Get the default address id which should be updated if wanted.
				$address = $user_obj->get_address_by_group();
				$address_id = $address['id'];
			}

			// Check if we have now a valid user object, if update the user address if wanted.
			if (!$user_obj->load_success() || !$this->update_user_address($row['id'], $user_obj->user_id, $address_id)) {
				return false;
			}

			// Set the current user object.
			return $this->session->validate_login($user_obj);
		}
	}

	/**
	 * Inserts or updates the address of the user if the authentication config wants to sync.
	 *
	 * @param int $server_id
	 *   the server id.
	 * @param int $user_id
	 *   the user id.
	 * @param int $address_id
	 *   the address id (optional, default = 0)
	 *
	 * @return boolean true if no errors occured, else false.
	 */
	private function update_user_address($server_id, $user_id, $address_id = 0) {

		// Get the authentication config for this server.
		$auth_config = new LdapAuthenticationConfigObj($server_id);

		// If we do not want to map address date, skip.
		if (!$auth_config->enable_mapping) {
			return true;
		}

		// Try loading the provided address id, if it did not exist and we do not want be in sync all the time, skip.
		$user_address = new UserAddressObj($address_id);
		if ($user_address->load_success() && !$auth_config->always_sync) {
			return true;
		}

		// Setup default address data.
		$user_address->user_id = $user_id;
		$user_address->parent_id = 0;

		// Create ldap queries where we get our mapping values.
		$values = array();

		// Decode our field mapping configuration.
		$mappings = json_decode($auth_config->field_mapping, true);
		$ldap_queries = array();

		// Loop through all mappings.
		foreach ($mappings AS $field => $mapping) {

			// Check if we have provided a seperate search dn.
			$map_param = explode("|", $mapping);

			// If we have not provided a search dn use the lookup dn.
			if (!isset($map_param[1])) {
				array_unshift($map_param, $auth_config->lookup_dn);
			}

			// Fill out our search query array, we group by dn's so we need only one query per search dn.
			if (!isset($ldap_queries[$map_param[0]])) {
				$ldap_queries[$map_param[0]] = array();
			}

			$ldap_queries[$map_param[0]][$field] = $map_param[1];
		}

		// Get the ldap instance.
		$ldap_interface = LDAPFactory::create_instance($server_id);

		// Load the user object.
		$user_obj = new UserObj($user_id);
		foreach ($ldap_queries AS $search_dn => $fields) {

			// Retrieve all attributes for the dn.
			$attributes = $ldap_interface->retrieve_attributes(str_replace('%username%', $user_obj->username, $search_dn));

			if (!empty($attributes)) {
				// Loop through all mapped entries.
				foreach ($fields AS $db_field => $ldap_field) {

					// If the attribute does not exist within the ldap entry, skip it.
					if (!isset($attributes[$ldap_field])) {
						continue;
					}

					// Set the value.
					$values[$db_field] = $attributes[$ldap_field];
				}
			}
		}

		// Update the address object.
		$user_address->set_fields($values);
		return $user_address->save_or_insert();
	}
}