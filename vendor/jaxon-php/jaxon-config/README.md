[![Build Status](https://github.com/jaxon-php/jaxon-config/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/jaxon-php/jaxon-config/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/jaxon-php/jaxon-config/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/jaxon-php/jaxon-config/?branch=main)
[![StyleCI](https://styleci.io/repos/916318447/shield?branch=main)](https://styleci.io/repos/916318447)
[![codecov](https://codecov.io/gh/jaxon-php/jaxon-config/graph/badge.svg?token=tgRCemFota)](https://codecov.io/gh/jaxon-php/jaxon-config)

[![Latest Stable Version](https://poser.pugx.org/jaxon-php/jaxon-config/v/stable)](https://packagist.org/packages/jaxon-php/jaxon-config)
[![Total Downloads](https://poser.pugx.org/jaxon-php/jaxon-config/downloads)](https://packagist.org/packages/jaxon-php/jaxon-config)
[![License](https://poser.pugx.org/jaxon-php/jaxon-config/license)](https://packagist.org/packages/jaxon-php/jaxon-config)

Jaxon Config
============

Jaxon Config saves config options in immutable objects.

**Install**

```bash
composer require jaxon-php/jaxon-config
```

**Usage**

Create a config setter.

```php
$setter = new \Jaxon\Config\ConfigSetter();
```

Create a config object with initial value.

```php
/** @var \Jaxon\Config\Config */
$config = $setter->newConfig([
    'a' => [
        'b' => [
            'c' => 'Value',
        ],
    ],
]);
```

Create a config reader.

```php
$reader = new \Jaxon\Config\ConfigReader($setter);
```

Read config options from a file.

```php
// A new config object is returned.
// From a PHP file.
$config = $reader->load($config, '/path/to/config/file.php');
// Or from a YAML file.
$config = $reader->load($config, '/path/to/config/file.yaml');
// Or from a JSON file.
$config = $reader->load($config, '/path/to/config/file.json');
```

Create an empty config object and set values.

```php
/** @var \Jaxon\Config\Config */
$config = $setter->newConfig();
// A new config object is returned.
$config = $setter->setOptions($config, [
    'a' => [
        'b' => [
            'c' => 'Value',
        ],
    ],
]);
```

Read values.

```php
$config->getOption('a'); // Returns ['b' => ['c' => 'Value']]
$config->getOption('a.b'); // Returns ['c' => 'Value']
$config->getOption('a.b.c'); // Returns 'Value'
```

Set a single value.

```php
// A new config object is returned.
$config = $setter->setOption($config, 'a.b.d', 'Another value');
```

Read values.

```php
$config->getOption('a'); // Returns ['b' => ['c' => 'Value', 'd' => 'Another value']]
$config->getOption('a.b'); // Returns ['c' => 'Value', 'd' => 'Another value']
$config->getOption('a.b.c'); // Returns 'Value'
$config->getOption('a.b.d'); // Returns 'Another value'
```

Set values with a prefix.

```php
// A new config object is returned.
$config = $setter->setOptions($config, [
    'd' => [
        'e' => 'Overwritten value',
    ],
    'f' => ['Array', 'Of', 'Values'],
], 'a.b');
```

Read values.

```php
$config->getOption('a.b'); // Returns ['c' => 'Value', 'd' => ['e' => 'Overwritten value']]
$config->getOption('a.b.d'); // Returns ['e' => 'Overwritten value']
$config->getOption('a.b.d.e'); // Returns 'Overwritten value'
$config->getOption('a.b.f'); // Returns ['Array', 'Of', 'Values']
```

Create a new config object.

```php
/** @var \Jaxon\Config\Config */
$config = $setter->newConfig([
    'b' => [
        'c' => 'Value',
    ],
    'd' => 'Value',
    'e' => 'Value',
    'f' => 'Value',
], 'a');
```

Read values.

```php
$config->getOption('a'); // Returns ['b' => ['c' => 'Value'], 'd' => 'Value', 'e' => 'Value', 'f' => 'Value']
```

Remove an entry.

```php
// A new config object is returned.
$config = $setter->unsetOption($config, 'a.e');
```

Read values.

```php
$config->getOption('a'); // Returns ['b' => ['c' => 'Value'], 'd' => 'Value', 'f' => 'Value']
```

Remove multiple entries.

```php
// A new config object is returned.
$config = $setter->unsetOptions($config, ['a.f', 'a.b']);
```

Read values.

```php
$config->getOption('a'); // Returns ['d' => 'Value']
```
