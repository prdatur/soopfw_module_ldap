<?php
/**
 * Provides an ajax request to delete the given LDAP server entry.
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @category Ajax
 */
class AjaxLdapDeleteServer extends AjaxModul {

	/**
	 * This function will be executed after ajax file initializing
	 */
	public function run() {

		//Initalize param struct
		$params = new ParamStruct();
		$params->add_required_param("id", PDT_INT);

		// Fill the params.
		$params->fill();

		//Parameters are missing
		if (!$params->is_valid()) {
			throw new SoopfwMissingParameterException();
		}

		//Right missing
		if (!$this->core->get_right_manager()->has_perm("admin.ldap.manage")) {
			throw new SoopfwNoPermissionException();
		}

		//Load the server entry
		$server_obj = new LdapServerObj($params->id);

		// If provided id is not valid.
		if (!$server_obj->load_success()) {
			throw new SoopfwWrongParameterException(t('No such solr server.'));
		}

		//Try to delete the entry
		if ($server_obj->delete()) {
			AjaxModul::return_code(AjaxModul::SUCCESS);
		}
		AjaxModul::return_code(AjaxModul::ERROR_DEFAULT);
	}
}
