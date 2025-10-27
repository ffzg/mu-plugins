#!/bin/sh

ls -d /srv/www/*/wp-content/plugins | cut -d/ -f-5 | while read path ; do
	cd $path
	echo -n "## $path - "
      	if [ -L $path/mu-plugins ] ; then
		if [ $( readlink $path/mu-plugins ) = '/srv/mu-plugins' ] ; then
			echo OK installed symlink
		else
			echo ERROR: symlink to somewhere else $(readlink $path/mu-plugins)
			exit 1
		fi
	elif [ -d $path/mu-plugins ] ; then
		echo -n EXISTING directory
		cd $path/mu-plugins
		ls /srv/mu-plugins/*.php | while read path2 ; do
			if [ -e $( basename $path2 ) ] ; then
				echo -n "$path2 installed "
			else
				sudo ln -sfv $path2
			fi
		done
		echo
	else
		sudo ln -sfv /srv/mu-plugins
	fi
done
