#!/bin/bash

if [[ -z $DATABASE_NAME || -z $XMLRPC_PORT || -z $MYSQL_USERNAME || -z $MYSQL_PASSWORD || -z $MYSQL_ADDRESS || -z $MYSQL_PORT ]];
then
echo "DATABASE_NAME, XMLRPC_PORT, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_ADDRESS, MYSQL_PORT environment vars must all be setted"
exit 1
fi;

echo "Editing XMLS..."

xmlstarlet edit --inplace --update "maniacontrol/database/name" -v $DATABASE_NAME ./ManiaControl/configs/server.xml
xmlstarlet edit --inplace --update "maniacontrol/server/port" -v $XMLRPC_PORT ./ManiaControl/configs/server.xml
xmlstarlet edit --inplace --update "maniacontrol/database/user" -v $MYSQL_USERNAME ./ManiaControl/configs/server.xml
xmlstarlet edit --inplace --update "maniacontrol/database/pass" -v $MYSQL_PASSWORD ./ManiaControl/configs/server.xml
xmlstarlet edit --inplace --update "maniacontrol/database/host" -v $MYSQL_ADDRESS ./ManiaControl/configs/server.xml
xmlstarlet edit --inplace --update "maniacontrol/database/port" -v $MYSQL_PORT ./ManiaControl/configs/server.xml

xmlstarlet edit --inplace --update "dedicated/system_config/xmlrpc_port" -v $XMLRPC_PORT ./UserData/Config/dedicated_cfg.txt