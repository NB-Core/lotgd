Package lemonphp/event
===
[![Build Status](https://travis-ci.org/lemonphp/event.svg?branch=master)](https://travis-ci.org/lemonphp/event)
[![Coverage Status](https://coveralls.io/repos/github/lemonphp/event/badge.svg?branch=master)](https://coveralls.io/github/lemonphp/event?branch=master)

A simple event dispatcher

Usage
---

```
use Lemon\Event\Event;
use Lemon\Event\EventDispatcher;

$dispatcher = new EventDispatcher();

// Add listener (listener is callable with event object as argument)
$dispatcher->addListener('event.type', function(Event $event) {
    echo $event->getEventType() . ' is fired';
});

// Add subscriber (subscriber is implemented by yourself)
$dispatcher->addSubscriber($subscriber);

$dispatcher->dispatch('event.type');
```

Changelog
---
See all change logs in [CHANGELOG.md][changelog]

Contributing
---
All code contributions must go through a pull request and approved by
a core developer before being merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send a pull request.

To ensure a consistent code base, you should make sure the code follows the [PSR-2][psr2].

If you would like to help take a look at the [list of issues][issues].

License
---
This project is released under the MIT License.   
Copyright © 2015-2016 LemonPHP Team.

[changelog]: https://github.com/lemonphp/event/blob/master/CHANGELOG.md
[psr2]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md
[issues]: https://github.com/lemonphp/event/issues