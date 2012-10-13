<ul>
<%foreach $servers AS $id => $name%>
	<li><a href='/admin/ldap/manage_authentication/<%$id%>'><%$name%></a></li>
<%/foreach%>
</ul>