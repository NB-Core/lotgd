[![Build Status](https://github.com/lagdo/facades/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/lagdo/facades/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/lagdo/facades/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/lagdo/facades/?branch=main)
[![StyleCI](https://styleci.io/repos/957151579/shield?branch=main)](https://styleci.io/repos/957151579)
[![codecov](https://codecov.io/gh/lagdo/facades/branch/main/graph/badge.svg?token=HERKC60CC1)](https://codecov.io/gh/lagdo/facades)

[![Latest Stable Version](https://poser.pugx.org/lagdo/facades/v/stable)](https://packagist.org/packages/lagdo/facades)
[![Total Downloads](https://poser.pugx.org/lagdo/facades/downloads)](https://packagist.org/packages/lagdo/facades)
[![License](https://poser.pugx.org/lagdo/facades/license)](https://packagist.org/packages/lagdo/facades)

Base classes for service facades
================================

This package provides base classes for service facades implementations.

The goal of the separation between this package and the framework related ones is to make the facades portable across different frameworks.
A service facade can be use without any change with various frameworks, provided that a package for this framework is available, or a `PSR-11` container can be provided.

The following packages are currently available:
- Symfony: https://github.com/lagdo/symfony-facades
- Laravel (yes): https://github.com/lagdo/laravel-facades
- CakePHP: https://github.com/lagdo/cake-facades
- Yii: https://github.com/lagdo/yii-facades

### Facades definition

The `Lagdo\Facades\AbstractFacade` abstract class is the base class for user defined service facades.

```php
namespace App\Facades;

use App\Services\MyService;
use Lagdo\Facades\AbstractFacade;

/**
 * @extends AbstractFacade<MyService>
 */
class MyFacade extends AbstractFacade
{
    /**
     * @inheritDoc
     */
    protected static function getServiceIdentifier(): string
    {
        return MyService::class;
    }
}
```

If for any reason a service doesn't need to be fetched from the container at each call, it can be saved in its facade class by using the `Lagdo\Facades\ServiceInstance` trait.

```php
namespace App\Facades;

use App\Services\MyService;
use Lagdo\Facades\AbstractFacade;
use Lagdo\Facades\ServiceInstance;

/**
 * @extends AbstractFacade<MyService>
 */
class MyFacade extends AbstractFacade
{
    use ServiceInstance;

    /**
     * @inheritDoc
     */
    protected static function getServiceIdentifier(): string
    {
        return MyService::class;
    }
}
```

> [!IMPORTANT]
> The `Lagdo\Facades\ServiceInstance` trait *must* be used in each service facade class, and not in a parent class. The same instance will be shared by all the classes inheriting the same base class using the trait, and the service facades will ot work as expected.

The service container will be called only once in the above example.

```php
    MyFacade::myMethod1(); // Calls the service container
    MyFacade::myMethod2(); // Doesn't call the service container
    MyFacade::myMethod1(); // Doesn't call the service container
```

### Container setup

The `Lagdo\Facades\ContainerWrapper` class gives access to the underlying container.

It needs to be provided with a `PSR-11` container, which will be done by the framework specific packages, but can also be done by the developer in case a package is not available.

```php
use Lagdo\Facades\ContainerWrapper;

ContainerWrapper::setContainer($container);
```

Contribute
----------

- Issue Tracker: github.com/lagdo/facades/issues
- Source Code: github.com/lagdo/facades

License
-------

The package is licensed under the 3-Clause BSD license.
