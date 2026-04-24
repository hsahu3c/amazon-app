#!/bin/bash
sudo service nginx restart
sudo aws s3 sync s3://remotedevtesting/config/ /var/www/html/remote/app/etc/
sudo -p /root/.ssh
sudo cp -f /home/ubuntu/.ssh/*  /root/.ssh/
sudo cp -rfv /var/www/html/remote/app/etc/composer.json /var/www/html/remote/
sudo chown -R ubuntu:ubuntu /var/www/html/remote/
cd /var/www/html/remote/ && php composer.phar install --ignore-platform-reqs
mkdir -p /var/www/html/remote/var/log
sudo chown -R ubuntu:ubuntu /var/www/html/remote/
sudo touch /var/www/html/remote/var/log/system.log
sudo chmod -R 777 /var/www/html/remote/var/
redis-cli flushall
php /var/www/html/remote/app/cli setup upgrade install
