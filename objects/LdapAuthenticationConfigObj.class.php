<?php

/**
 * This object holds all configured authentication engines for the ldap servers.
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @category Objects
 */
class LdapAuthenticationConfigObj extends AbstractDataManagement
{
	/**
	 * Define constances
	 */
	const TABLE = 'ldap_authentication_config';

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

		$this->db_struct->add_hidden_field("id", t("LDAP ServerID"), PDT_INT);
		$this->db_struct->add_required_field("lookup_dn", t("LDAP user lookup dn"), PDT_STRING);
		$this->db_struct->add_required_field("enable", t("Enable"), PDT_INT, 0, 'UNSIGNED');
		$this->db_struct->add_required_field("enable_mapping", t("Enable mapping"), PDT_INT, 0, 'UNSIGNED');
		$this->db_struct->add_required_field("always_sync", t("LDAP sync data"), PDT_INT, 0, 'UNSIGNED');
		$this->db_struct->add_required_field("field_mapping", t("Serialized mapping data"), PDT_TEXT);

		$this->db_struct->add_index(MysqlTable::INDEX_TYPE_INDEX, 'enable');

		$this->set_default_fields();

		if (!empty($id)) {
			if (!$this->load($id, $force_db)) {
				return false;
			}
		}
	}
}