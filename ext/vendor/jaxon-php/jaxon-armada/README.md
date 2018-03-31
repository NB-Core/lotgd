Jaxon Armada
============

Jaxon is an open source PHP library for easily creating Ajax web applications.
It allows into a web page to make direct Ajax calls to PHP classes that will in turn update its content, without reloading the entire page.

This package provides advanced features for Jaxon-based applications.

The [Jaxon classes](https://www.jaxon-php.org/docs/armada/classes.html) in an Armada-based application inherit from `\Jaxon\Sentry\Armada`, which provides them with functions to handle responses, views, sessions, requests and pagination.

#### Views and sessions

Armada provides a [common API for views](https://www.jaxon-php.org/docs/armada/views.html), that can be used with various template engines: Smarty, Twig, Blade and Dwoo, among others.
Armada also provides a simple API for storing and retrieving data from [user sessions](https://www.jaxon-php.org/docs/armada/sessions.html).

The views and sessions APIs in Armada are the same as in framework integration packages. 

#### Request and Paginator factories

The [Request and Paginator factories](https://www.jaxon-php.org/docs/armada/classes.html) create a request (resp. a list of requests) to a method in a Jaxon class.
Both implement a fluent interface which transform a call to a method into a request to the same method in the linked class.

Documentation
-------------

The Armada documentation is available in [English](http://www.jaxon-php.org/en/docs/armada/operation.html) and [French](http://www.jaxon-php.org/fr/docs/armada/operation.html).

Some sample codes are provided in the [jaxon-php/jaxon-examples](https://github.com/jaxon-php/jaxon-examples) package, and demonstrated in [the website](http://www.jaxon-php.org/examples/advanced/armada.html).

Contribute
----------

- Issue Tracker: github.com/jaxon-php/jaxon-armada/issues
- Source Code: github.com/jaxon-php/jaxon-armada

License
-------

The project is licensed under the BSD license.
