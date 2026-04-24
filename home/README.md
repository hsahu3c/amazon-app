# Phalcon Core Module # 

## Installation ##
- ### Install Phalcon ###
    - Run below commands  
    -       git clone git://github.com/phalcon/cphalcon.git
            cd cphalcon/build

             Use this for xampp environment
            ./install --phpize /opt/lampp/bin/phpize --php-config /opt/lampp/bin/php-config

            For individual component environment (lampp stack) use this
            ./install --phpize /usr/bin/phpize7.2 --php-config /usr/bin/php-config7.2

    - After running last command you will get the path of "phalcon.so"  
    - Open /opt/lampp/etc/php.ini and add extension=phalcon.so
- ### Install Php redis ###
    - run below commands
    -       git clone https://github.com/phpredis/phpredis.git
            cd phpredis
            /opt/lampp/bin/phpize
            ./configure --with-php-config=/opt/lampp/bin/php-config
            make && make install
    - After running last command you will get the path of "redis.so"  
    - Open /opt/lampp/etc/php.ini and add extension=redis.so
- ### Install Redis ###
    - Run below commands
    -     wget http://download.redis.io/redis-stable.tar.gz
          tar xvzf redis-stable.tar.gz
          cd redis-stable
          make
    - #### Start Redis ####
        - ./redis-stable/src/redis-server
**

# Install App
**Dipendencies:-**
 - php7.2 
 - redis
 - mongo4

**PHP LIbraries:-**

 - redis
 - psr 
 - mongodb 
  - phalcon4

## Steps
1 - Clone the connector branch of back-end app from below repo:-

    git@github.com:cedcommerce/phalcon.git
2 - mkdir -p ./var/log

3 - touch ./var/log/system.log

4 - cp composer.json.sample composer.json

5 - edit composer.json and add required modules 

6 - run composer install

7 - Copy and update config files from sample files (app/etc/)
 - cp config.php.sample config.php
 -  cp redis.php.sample redis.php   
 -  cp aws.php.sample aws.php
 -   cp dynamo.php.sample dynamo.php


8 - php /app/home/app/cli setup upgrade install


**Follow below doc for basic cli commands**

    https://github.com/cedcommerce/phalcon-docs/blob/master/docs/resources/cli.md


## **Frontend apps setup:-**

1. for managing roles and resources clone master branch of below repo - 

    git@github.com:cedcommerce/connector-common-view-admin.git

2. for managing manage apps for remote clone master branch of below repo -

    git@github.com:cedcommerce/apiacess-connector-frontend.git

          
- ### NOTE ###
 after installation error appearing "URL not found"
 
 -> make sure URL Reqrite module is enable
 
 -> and redirect ALL is set from apache configuration file
