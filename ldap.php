<?php

/**
 * ldap action module
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 */
class ldap extends ActionModul {

	/**
	 * Default method
	 * @var string
	 */
	protected $default_methode = "manage";

	/**
	 * Implementation of get_admin_menu()
	 * @return array the menu
	 */
	public function get_admin_menu() {

		$childs = array();
		$configured_servers = LDAPFactory::get_all_instances();
		foreach ($configured_servers AS $server_id => $server_name) {
			$childs[] = array(
				'#title' => $server_name, //The main title
				'#link' => "/admin/ldap/manage_authentication/" . $server_id, // The main link
				'#perm' => 'admin.ldap.manage', //Perm needed
			);
		}
		return array(
			999 => array(//Order id, same order ids will be unsorted placed behind each
				'#id' => 'soopfw_ldap', //A unique id which will be needed to generate the submenu
				'#title' => t("LDAP"), //The main title
				'#link' => "/ldap/manage", // The main link
				'#perm' => 'admin.ldap', //Perm needed
				'#childs' => array(
					array(
						'#title' => t("Manage Server"), //The main title
						'#link' => "/admin/ldap/manage", // The main link
						'#perm' => 'admin.ldap.manage', //Perm needed
					),
					array(
						'#title' => t("Authentication"), //The main title
						'#link' => "/admin/ldap/manage_authentication", // The main link
						'#perm' => 'admin.ldap.manage', //Perm needed
						'#childs' => $childs,
					),
				)
			)
		);
	}

	/**
	 * Action: manage_authentication
	 *
	 * Provide configuration options to use ldap servers as an authentication engine.
	 *
	 * @param int $server_id
	 *   the server id. (optional, default = '')
	 */
	public function manage_authentication($server_id = '') {
		//Check perms
		if (!$this->right_manager->has_perm("admin.ldap.manage", true)) {
			throw new SoopfwNoPermissionException();
		}

		if (empty($server_id)) {
			$configured_servers = LDAPFactory::get_all_instances();
			if (empty($configured_servers)) {
				throw new SoopfwErrorException(t('No LDAP-Servers found, please configurate a minimum of one first.'));
			}

			$this->title(t('Choose a server'), t('Please choose a server which you want to configurate for the authentication'));
			$this->smarty->assign_by_ref('servers', $configured_servers);
		}
		else {
			$server = new LdapServerObj($server_id);
			if (!$server->load_success()) {
				throw new SoopfwWrongParameterException(t('No such server'));
			}

			$config = new LdapAuthenticationConfigObj($server_id);



			$this->title(t('Authentication configuration of server: @server', array(
				'@server' => $server->server
			)), t('Here you can configurate the authentication options for this server.'));

			$form = new Form('ldap_authentication_config');

			$form->add(new Fieldset('main_config', t('Main config')));
			$form->add(new Checkbox('enable', 1, $config->enable, t('Enable this server for authentication')));

			$form->add(new Textfield('lookup_dn', $config->lookup_dn, t('User authentication lookup dn'), t('Please provide the FULL dn where the user will be found, use %username% as a replacement key where the username will be replaced.
<br>For example if the users dn of user "<b>example</b>" is <b>uid=example,dc=domain,dc=com</b> then the value whould be: <b>uid=%username%,dc=domain,dc=com</b>')));

			$form->add(new Checkbox('enable_mapping', 1, $config->enable_mapping, t('Enable address mapping'), t('If this is enabled the configured mappings will be used for the address in order an user account needs to be created.')));
			$form->add(new Checkbox('always_sync', 1, $config->always_sync, t('Always sync LDAP-Data'), t('If this is enabled the configured mapping will always be synced no matter what the user change')));

			$form->add(new Fieldset('field_mapping', t('Field mapping'), t('Please provide as much as you can, if you only enter a single attribute string it will be search within the lookup_dn configured above.
<br>If you want to search within another dn you can provide the dn piped with the attribute name, the dn will work the same as the lookup_dn so you also can use %username% which will be replaced with the actual username.
<br>For example if you want to retrieve the "<b>title</b>" attribute within the dn <b>ou=address,uid=example,dc=domain,dc=com</b> where <b>example</b> is again the username, you would write: <b>ou=address,uid=%username%,dc=domain,dc=com|title</b> ')));

			$fields = array(
				'email' => t('Email'),
				'title' => t('Title'),
				'company' => t('Company'),
				'lastname' => t('Lastname'),
				'firstname' => t('Firstname'),
				'nation' => t('Nation'),
				'zip' => t('Zip'),
				'city' => t('City'),
				'address' => t('Address'),
				'address2' => t('Address2'),
				'phone' => t('Phone'),
				'mobile' => t('Mobile'),
				'fax' => t('Fax'),
			);

			$field_mapping = json_decode($config->field_mapping, true);
			if (empty($field_mapping)) {
				$field_mapping = array();
			}

			foreach ($fields AS $field => $title) {
				$val = "";
				if (isset($field_mapping[$field])) {
					$val = $field_mapping[$field];
				}
				$form->add(new Textfield('mapping[' . $field . ']', $val, t('Map @map', array('@map' => $title))));
			}


			if ($form->check_form()) {
				$values = $form->get_array_values();
				$config->set_fields($values);
				$config->id = $server_id;
				$config->field_mapping = json_encode($values['mapping']);
				if ($config->save_or_insert()) {
					$this->core->message(t('Configuration saved.'), Core::MESSAGE_TYPE_SUCCESS);
				}
				else {
					$this->core->message(t('Could not save Configuration.'), Core::MESSAGE_TYPE_ERROR);
				}
			}
			$this->static_tpl = 'form.tpl';

		}
	}
	/**
	 * Action: manage
	 *
	 * Display and/or search all servers
	 */
	public function manage() {
		//Check perms
		if (!$this->right_manager->has_perm("admin.ldap.manage", true)) {
			throw new SoopfwNoPermissionException();
		}

		// Setting up title and description.
		$this->title(t("Manage LDAP server"), t("Here we can manage and configurate the LDAP servers"));

		//Setup search form
		$form = new SessionForm("search_ldap_overview", t("Search server:"));
		$form->add(new Textfield("server", '', t('Servername')));
		$form->add(new Submitbutton("search", t("Search")));
		$form->assign_smarty();

		//Check form and add errors if form is not valid
		$form->check_form();

		$filter = new DatabaseFilter(LdapServerObj::TABLE);

		// Fill the database filter.
		foreach ($form->get_values() AS $field => $val) {
			if (empty($val)) {
				continue;
			}
			$filter->add_where($field, $this->db->get_sql_string_search($val, "*.*", false), 'LIKE');
		}

		//Init pager
		$pager = new Pager(50, $filter->select_count());
		$pager->assign_smarty("pager");

		// Setup paging limit and offset
		$filter->limit($pager->max_entries_per_page());
		$filter->offset($pager->get_offset());

		//Assign found results
		$this->smarty->assign_by_ref("servers", $filter->select_all());
	}

	/**
	 * Action: save_server.
	 *
	 * Save or create a ldap server, if $id is provided update the current one
	 * if left empty it will create a new server
	 *
	 * @param int $id
	 *   the server id (optional, default = "")
	 */
	public function save_server($id = "") {
		// Check perms.
		if (!$this->right_manager->has_perm("admin.ldap.manage", true)) {
			throw new SoopfwNoPermissionException();
		}

		$this->static_tpl = 'form.tpl';

		$form = new ObjForm(new LdapServerObj($id), "");
		$form->set_ajax(true);
		$form->add_js_success_callback("save_server_success");

		// Add a save submit button.
		$form->add(new Submitbutton("insert", t("Save")));

		// Check if form was submitted.
		if ($form->check_form()) {

			// Start transaction.
			$form->get_object()->transaction_auto_begin();

			// If save operation succeed.
			if ($form->save_or_insert()) {

				$obj = $form->get_object();
				$factory = new LDAPFactory();
				if ($factory->create_instance($obj->server)) {

					// Setup success message.
					$form->get_object()->transaction_auto_commit();
					$this->core->message("Server saved ", Core::MESSAGE_TYPE_SUCCESS, true, $form->get_values(true));

				}
				else {

					// Server infos not correct.
					$form->get_object()->transaction_auto_rollback();
					$this->core->message("Could not connect to LDAP server", Core::MESSAGE_TYPE_ERROR, true);

				}

			}
			else {

				// Else setup error message.
				$form->get_object()->transaction_auto_rollback();
				$this->core->message("Error while saving server", Core::MESSAGE_TYPE_ERROR, true);

			}
		}
	}
}