# Soopfw module: LDAP

LDAP is a [SoopFw](http://soopfw.org) extension.

The module will provide LDAP access for the [SoopFw framework](http://soopfw.org).

# How to use
First of all download this module and install the source files under **{docroot}/modules/ldap**.
After that you need to enable the module.

Login with the admin account or use the **clifs** command to enable/install the module.

Now you need to be really logged in with the admin account.
Go to the ldap menu entry and create a server.

If the server is successfully created you can use the LDAPFactor to retrieve the LDAPInterface object
which provides the ldap read/write/delete or search functionality for ldap entries.

# Login handler
The module comes with an LDAP login handler too.
To use the LDAP login handler you have to setup a ldap server or use an existing one.

Got to the menu **LDAP -> Authentication** and choose the wanted server.

Within the new Config form you are able to configurate the authentication configuration.

After you setup and saved all needed / wanted configuration go to the Main framework configuration.
Click on the button "**Configurate login handler**" and activate the ldap authentication engine.

That's all, now you are able to use your accounts within the ldap directory.