#!/bin/bash

IFS_BK=$IFS
IFS='
'

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
GW_DIR=${PLUGIN_DIR%/*/*}
while [ ! -f "${GW_DIR}/config/mapfile.outputformats.inc" ]
do
	echo "Insert gisclient 3 installation directory:"
	read GW_DIR
done	

for i in $(ls -R $PLUGIN_DIR | awk ' /:$/&&f{s=$0;f=0} /:$/&&!f{sub(/:$/,"");s=$0;f=1;next} NF&&f{ print s"/"$0 }')
do 
	PLUGIN_ELEM=${i#$PLUGIN_DIR}
	[ -L "${GW_DIR}${PLUGIN_ELEM}" ] && { rm ${GW_DIR}${PLUGIN_ELEM}; echo "$PLUGIN_ELEM uninstalled"; }
done

IFS=$IFS_BK
