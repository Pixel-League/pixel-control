#!/bin/sh
while [ -z $(pgrep php) ]
do
    php ManiaControl.php >ManiaControl.log 2>&1 &
    echo $! > ManiaControl.pid
    echo "Trying to start ManiaControl..."
    sleep 5
done

echo "Maniacontrol started !"