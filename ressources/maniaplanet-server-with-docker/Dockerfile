FROM ubuntu:20.04

ARG DEBIAN_FRONTEND=noninteractive

WORKDIR "/home/maniaplanet"

COPY server .

RUN apt-get update && \
    apt-get install -y wget unzip curl php php-mysqli php-curl php-xmlrpc php-zip php-xml php-mbstring git xmlstarlet

WORKDIR "/home/server"

CMD cp -rn /home/maniaplanet/* . && \
    rm -r /home/maniaplanet && \
    chmod +x ManiaPlanetServer && chmod +x editXMLs.sh && \
    ./editXMLs.sh && \
    (cd /home/server/ManiaControl && sh ManiaControl.sh &>/dev/null &) && \
    ./ManiaPlanetServer /nodaemon /title=${TITLE_PACK} /servername=${SERVER_NAME} /login=${DEDICATED_USERNAME} /password=${DEDICATED_PASSWORD} /game_settings=MatchSettings/.txt /dedicated_cfg=dedicated_cfg.txt