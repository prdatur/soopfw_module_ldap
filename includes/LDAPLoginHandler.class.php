<?php

/**
 * Provides a ldap login handler.
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @category Module
 */
class LDAPLoginHandler extends AbstractLoginHandler implements LoginHandler
{
	/**
	 * Check if the given credentials are valid and if so setup a new session object
	 * or update the old one, also update the last login time.
	 *
	 * @param string $username
	 *   the username.
	 * @param string $password
	 *   the crypted password.
	 *
	 * @return boolean return true if provided credentials are valid, else false.
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
				$user_obj->account_type = 'ldap';
				$user_obj->language = $this->core->current_language;
				$user_obj->registered = date(DB_DATETIME, TIME_NOW);
				$user_obj->last_login = date(DB_DATETIME, TIME_NOW);

				// Insert the data.
				if (!$user_obj->create_account(null, false, false)) {
					$user_obj = new UserObj();
					continue;
				}

				SystemHelper::audit(t('User created from LDAP-LoginHandler "@username".', array('@username' => $username)), 'session', SystemLogObj::LEVEL_NOTICE);
			}
			else {
				// Get the default address id which should be updated if wanted.
				$address = $user_obj->get_address_by_group();
				$address_id = $address['id'];
			}

			// Verify that the loaded user is an ldap user.
			if ($user_obj->account_type != 'ldap') {
				$this->core->message(t('This username is already taken from another login handler, Sorry you can not use this username anymore.'), Core::MESSAGE_TYPE_ERROR);
				return false;
			}

			// User must exist
			if(!$user_obj->load_success()) {
				return false;
			}

			// Check if we have now a valid user object, if update the user address if wanted.
			// We need also to check that the account type is an ldap account.
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
	 *   the address id. (optional, default = 0)
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

		// Prevent updating an email address if it already exist for a different account and system is configurated to accept only unique emails.
		if (!empty($user_address->values_changed['email']) && $this->core->get_dbconfig("user", user::CONFIG_SIGNUP_UNIQUE_EMAIL, 'no') == 'yes') {
			$filter = DatabaseFilter::create(UserAddressObj::TABLE)
				->add_where('email', $user_address->values_changed['email'])

				// Duplicated emails are just allowed within the same user.
				->add_where('user_id', $user_obj->user_id, '!=');

			// Change back the email to the original one if we have already the new email within another user.
			if ($filter->select_first()) {
				$user_address->email = $user_address->get_original_value('email');
			}
		}
		return $user_address->save_or_insert();
	}
}