CONTENTS OF THIS FILE
---------------------

 * [Introduction](#introduction)
 * [Requirements](#requirements)
 * [Installation](#installation)
 * [Configuration](#configuration)
 * [Maintainers](#maintainers)


INTRODUCTION
------------

This module will create a commerce payment plugin for Moldova Agroindbank.

You can install this module: 

```
composer require drupal/commerce_maib
```

REQUIREMENTS
------------

>Before start using this module you need to request a .pfx certificate for your site from **Moldova Agroindbank**.

>**Moldova Agroindbank** support payment just in Moldovan Leu.

This module requires the following modules:

* Commerce v2 (https://www.drupal.org/project/commerce)

 Also this module requires library outside of Drupal core.

* Maib Api (https://github.com/indrivo/maib-api)

INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. Visit
   https://www.drupal.org/node/1897420 for further information.

CONFIGURATION
-------------
 
 * Create payment gateway: /admin/commerce/config/payment-gateways

 * Set payment plugin name. Ex Maib

 * Select **MAIB (Off-site redirect)** from plugins list.

 * After you have received the pfx file from Moldova Agroindbank it is necessary to unpack.

 * Move the received files somewhere on the server and set field values: 
   
   - Path to the private key PEM file 
   - Password for private key
   - Path to the certificate PEM file containing public key

 * Select Transaction type.

 * Create order restricted by order currency for Moldovan Leu.

MAINTAINERS
-----------

Current maintainers:
 * Indrivo - https://www.drupal.org/user/