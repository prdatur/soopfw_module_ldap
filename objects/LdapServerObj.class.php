<?php

/**
 * The ldap server object which holds all our configured ldap servers
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @category Objects
 */
class LdapServerObj extends AbstractDataManagement
{
	/**
	 * Define constances
	 */
	const TABLE = 'ldap_servers';

	/**
	 * Constructor
	 *
	 * @param int $id
	 *   the server id (optional, default = "")
	 * @param boolean $force_db
	 *   if we want to force to load the data from the database (optional, default = false)
	 */
	public function __construct($id = "", $force_db = false) {
		parent::__construct();
		$this->db_struct = new DbStruct(self::TABLE);

		$this->db_struct->set_cache(true);

		$this->db_struct->add_reference_key("id");
		$this->db_struct->set_auto_increment("id");

		$this->db_struct->add_hidden_field("id", t("LDAP ServerID"), PDT_INT);

		$this->db_struct->add_required_field("server", t("LDAP Servername"), PDT_STRING);
		$this->db_struct->add_required_field("host", t("LDAP host"), PDT_STRING);
		$this->db_struct->add_required_field("basedn", t("LDAP basedn"), PDT_STRING);
		$this->db_struct->add_required_field("port", t("LDAP port"), PDT_INT, 389, 'UNSIGNED');
		$this->db_struct->add_required_field("admindn", t("LDAP admindn"), PDT_STRING);
		$this->db_struct->add_required_field("password", t("LDAP password"), PDT_PASSWORD);

		$this->db_struct->add_index(MysqlTable::INDEX_TYPE_UNIQUE, 'server');

		$this->set_default_fields();

		if (!empty($id)) {
			if (!$this->load($id, $force_db)) {
				return false;
			}
		}
	}

	/**
	 * Delete the given data and also, if found, the authentication configuration
	 *
	 * @return boolean true on success, else false
	 */
	public function delete() {
		$id = $this->id;
		if (parent::delete()) {
			$authn_config = new LdapAuthenticationConfigObj($id);
			return $authn_config->delete();
		}

		return false;
	}
}