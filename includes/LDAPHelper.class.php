<?php

/**
 * Provides a class to provide helper functions for ldap based operations.
 *
 * @copyright Christian Ackermann (c) 2010 - End of life
 * @author Christian Ackermann <prdatur@gmail.com>
 * @package modules.ldap.includes
 */
class LDAPHelper
{
	/**
	 * Escape a single string.
	 *
	 * @param string $str
	 *   the value to be escaped.
	 *
	 * @return string the escaped variable
	 */
	public static function escape($str) {
		return preg_replace("/([,\\#+<>;\"=])/", '\\\\${1}', $str);
	}

	/**
	 * Escape the given parameter, if an array provided it will be escaped recursive
	 *
	 * @param string|array &$values
	 *  The values to be escaped.
	 */
	public static function escape_recursive(&$values) {
		if (is_array($values)) {
			foreach ($values AS &$value) {
				self::escape_recursive($value);
			}
		}
		else {
			$values = self::escape($values);
		}
	}

	/**
	 * Returns the md5 crypted, packed and base 64 encoded string with {MD5} as prefix.
	 *
	 * @param string $password
	 *   The descrypted password.
	 *
	 * @return string the encrypted password
	 */
	public static function crypt_password($password) {
		return "{MD5}" . base64_encode(pack("H*", md5($password)));
	}
}