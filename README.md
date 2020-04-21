CONTENTS
---------------------

 * [Introduction](#introduction)
 * [Requirements](#requirements)
 * [Installation](#installation)
 * [Configuration](#configuration)
 * [Maintainers](#maintainers)


INTRODUCTION
------------

This module will create a commerce payment plugin for Moldova Agroindbank.


REQUIREMENTS
------------

>Before start using this module you need to request a .pfx certificate for your domain from **Moldova Agroindbank**.

>**Moldova Agroindbank** support payment just in Moldovan Leu.

This module requires:

* **Module**: Commerce v2 (https://www.drupal.org/project/commerce)
* **Library**: Maib Api (https://github.com/indrivo/maib-api)


INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. Visit
   https://www.drupal.org/node/1897420 for further information.

CONFIGURATION
-------------
 
 * Create payment gateway: /admin/commerce/config/payment-gateways
 * Set payment plugin name. *Ex **Maib***.
 * Select **MAIB (Off-site redirect)** from plugins list.
 * After you have received the .pfx file from Moldova Agroindbank it is necessary to unpack.
 * Move the received files outside of **web** directory. *Ex **certs** folder* : 
   - Path to the private key PEM file. | *Ex /var/www/html/certs/private-key.pem*
   - Password for private key | *Password provided by MAIB from the .pfx file*
   - Path to the certificate PEM file containing public key | */var/www/html/certs/certificate.pem*
 * Select Transaction type.
 * Create order restricted by order currency for Moldovan Leu.

MAINTAINERS
-----------

Current maintainers:
 * Indrivo - https://github.com/indrivo