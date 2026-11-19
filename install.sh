#!/bin/sh

find /srv/www -name wp-config.php -not -path '*/plugins/duplicator/*' | sort | while read path ; do
	path=$( echo "$path" | sed 's,/wp-config.php,/wp-content,' )
	[ -d "$path" ] || continue
	echo -n "## $path - "

	if [ -e "$path/plugins/wp-fail2ban" ] ; then
		wp --allow-root --path="$(dirname "$path")" plugin uninstall wp-fail2ban --deactivate
	fi

	if [ -L "$path/mu-plugins" ] ; then
		link_target=$( readlink "$path/mu-plugins" )
		if [ "${link_target%/}" = '/srv/mu-plugins' ] ; then
			echo OK installed symlink
		else
			echo ERROR: symlink to somewhere else "$link_target"
			exit 1
		fi
	elif [ -d "$path/mu-plugins" ] ; then
		echo -n "EXISTING directory "
		for path2 in /srv/mu-plugins/*.php ; do
			bname=$( basename "$path2" )
			if [ -e "$path/mu-plugins/$bname" ] ; then
				echo -n "$path2 installed "
			else
				sudo ln -sfv "$path2" "$path/mu-plugins/$bname"
			fi
		done
		echo
	else
		sudo ln -sfv /srv/mu-plugins "$path/mu-plugins"
	fi
done
