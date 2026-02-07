[![Build Status](https://github.com/jaxon-php/jaxon-storage/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/jaxon-php/jaxon-storage/actions)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/jaxon-php/jaxon-storage/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/jaxon-php/jaxon-storage/?branch=main)
[![StyleCI](https://styleci.io/repos/1109084794/shield?branch=main)](https://styleci.io/repos/1109084794)
[![codecov](https://codecov.io/gh/jaxon-php/jaxon-storage/graph/badge.svg?token=OFyT9WzVxt)](https://codecov.io/gh/jaxon-php/jaxon-storage)

[![Latest Stable Version](https://poser.pugx.org/jaxon-php/jaxon-storage/v/stable)](https://packagist.org/packages/jaxon-php/jaxon-storage)
[![Total Downloads](https://poser.pugx.org/jaxon-php/jaxon-storage/downloads)](https://packagist.org/packages/jaxon-php/jaxon-storage)
[![Latest Unstable Version](https://poser.pugx.org/jaxon-php/jaxon-storage/v/unstable)](https://packagist.org/packages/jaxon-php/jaxon-storage)
[![License](https://poser.pugx.org/jaxon-php/jaxon-storage/license)](https://packagist.org/packages/jaxon-php/jaxon-storage)

File storage for the Jaxon library
=================================

This package provides a tiny wrapper for file storage for the Jaxon library using the [PHP League Flysystem](https://flysystem.thephpleague.com) library.

## Features

The library features are provided in the `Jaxon\Storage\StorageManager` class, which implements three functions.

Starting from version 1.1.0, a global function is available to get the instance of the storage manager.

```php
use function Jaxon\Storage\storage;
```

This function creates and returns a static instance of the `Jaxon\Storage\StorageManager` class.

#### Register an adapter

This function registers an adapter from the [Flysystem](https://flysystem.thephpleague.com) library.

```php
    /**
     * @param string $sAdapter
     * @param Closure $cFactory
     *
     * @return void
     */
    public function register(string $sAdapter, Closure $cFactory)
```

The first parameter is the adapter id, and the second is a closure which takes a root dir and an optional array of options as parameters, and returns a `League\Flysystem\FilesystemAdapter` object configured for file input and output at the given location.

By default, the library registers an adapter for the local filesystem.

```php
use League\Flysystem\Local\LocalFilesystemAdapter;
use function Jaxon\Storage\storage;

// Local file system adapter
storage()->register('local', function(string $sRootDir, array $aOptions) {
    return new LocalFilesystemAdapter($sRootDir, ...$aOptions);
});
```

An adapter can be registered as an alias of an already registered one.
This is useful for example for using the same adapter with different options.

```php
use function Jaxon\Storage\storage;

// The "uploads" adapter is an alias of the local file system adapter
storage()->register('uploads', 'local');
```

#### Create a file input/output object

A [Flysystem](https://flysystem.thephpleague.com) object for file input and output is created by chaining the `adapter()` and `make()` functions.

```php
use function Jaxon\Storage\storage;

$storage = storage()
    ->adapter('local')
    ->make('/var/www/storage/uploads');
$storage->write('uploaded-file.txt', $uploadedContent)
```

The `adapter()` function takes the id of a registered adapter as parameter, while the `make()` function takes the path to the root dir.

The code snippet below writes the given content in the `/var/www/storage/uploads/uploaded-file.txt` file.

The adapter and directory options can be passed to the `adapter()` and `make()` functions.
The adapter options are set on the adapter object (e.g `LocalFilesystemAdapter`), while the directory options are set on the returned `Filesystem` object. Their values are then described in their respective documentations.

```php
use function Jaxon\Storage\storage;

$aAdapterOptions = [
    'lazyRootCreation' => true,
];
$aDirOptions = [
    'config' => [
        'public_url' => '/uploads',
    ],
];
$storage = storage()
    ->adapter('local', $aAdapterOptions)
    ->make('/var/www/storage/uploads', $aDirOptions);
$storage->write('uploaded-file.txt', $uploadedContent)
```

#### Create a file input/output object from a Jaxon Config object

The [Flysystem](https://flysystem.thephpleague.com) object for file input and output can also be created from option values in a `Jaxon\Config\Config` object.
For the storage with id `uploads`, the `adapter`, `dir` and `options` values will be read in the `storage.stores.uploads` entry.

```php
use Jaxon\Config\ConfigSetter;
use function Jaxon\Storage\storage;

// Set the storage config
$setter = new ConfigSetter();
$config = $setter->newConfig([
    'storage' => [
        'adapters' => [
            // Adapters options
            'local' => [], // Optional
        ]
        'stores' => [
            'uploads' => [
                'adapter' => 'local',
                'dir' => '/var/www/storage/uploads',
                // 'options' => [], // Optional
            ],
        ],
    ],
]);
storage()->setConfig($config);

// Write a file
$storage = storage()->get('uploads');
$storage->write('uploaded-file.txt', $uploadedContent)
```

The code snippet above writes the given content in the `/var/www/storage/uploads/uploaded-file.txt` file, as in the previous example.

```php
use function Jaxon\Storage\storage;

$storage = storage()->get('uploads');
$storage->write('uploaded-file.txt', $uploadedContent)
```

Each adapter can also be defined as an alias of an already defined adapter.

```php
$config = $setter->newConfig([
    'storage' => [
        'adapters' => [
            // Adapters options
            'uploads' => [
                'alias' => 'local',
                'options' => [], // Local adapter options for the uploads
            ],
            'exports' => [
                'alias' => 'local',
                'options' => [], // Local adapter options for the exports
            ],
        ],
        'stores' => [],
    ],
]);
```

## Using without the Jaxon library

Starting from version 1.1.0, the Jaxon Storage classes do not depend on the Jaxon Core classes anymore.
As a consequence, some features will not be automatically available, and will need an extra setup.

#### The locale for translations

Without the Jaxon Core library, the Jaxon Storage will create its own instance of the `Jaxon\Utils\Translation\Translator` class, which will then need to be set.

```php
use function Jaxon\Storage\storage;

// Set the translation locle to french.
storage()->translator()->setLocale('fr');
```

#### The storage config options

A call to the `storage()->get()` function will by default throw an exception due to missing config options.
The library then needs to be provided with a `Jaxon\Config\Config` object populated with the config options, or a closure which returns one, using the `setConfig()` method.

## Register additional adapters

The [Flysystem](https://flysystem.thephpleague.com) library provides adapters for many other filesystems, which can be registered with this library.

They are provided in separate packages, which need to be installed first.

#### AWS S3 file system adapter

```php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use function Jaxon\Storage\storage;

storage()->register('aws-s3', function(string $sRootDir, array $aOptions) {
    $client = new S3Client($aOptions['client'] ?? []);
    return new AwsS3V3Adapter($client, $aOptions['bucket'] ?? '', $sRootDir);
});
```

#### Async AWS S3 file system adapter

```php
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use function Jaxon\Storage\storage;

storage()->register('async-aws-s3', function(string $sRootDir, array $aOptions) {
    $client = isset($aOptions['client']) ? new S3Client($aOptions['client']) : new S3Client();
    return new AsyncAwsS3Adapter($client, $aOptions['bucket'] ?? '', $sRootDir);
});
```

#### Google Cloud file system adapter

```php
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\AzureBlobStorage\GoogleCloudStorageAdapter;
use function Jaxon\Storage\storage;

storage()->register('google-cloud', function(string $sRootDir, array $aOptions) {
    $storageClient = new StorageClient($aOptions['client'] ?? []);
    $bucket = $storageClient->bucket($aOptions['bucket'] ?? '');
    return new GoogleCloudStorageAdapter($bucket, $sRootDir);
});
```

#### Microsoft Azure file system adapter

```php
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use function Jaxon\Storage\storage;

storage()->register('azure-blob', function(string $sRootDir, array $aOptions) {
    $client = BlobRestProxy::createBlobService($aOptions['dsn']);
    return new AzureBlobStorageAdapter($client, $aOptions['container'], $sRootDir);
});
```

#### FTP file system adapter

```php
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use function Jaxon\Storage\storage;

storage()->register('ftp', function(string $sRootDir, array $aOptions) {
    $aOptions['root'] = $sRootDir;
    $xOptions = FtpConnectionOptions::fromArray($aOptions);
    return new FtpAdapter($xOptions);
});
```

#### SFTP V2 file system adapter

```php
use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use function Jaxon\Storage\storage;

storage()->register('sftp-v2', function(string $sRootDir, array $aOptions) {
    $provider = new SftpConnectionProvider(...$aOptions);
    return new SftpAdapter($provider, $sRootDir);
});
```

#### SFTP V3 file system adapter

```php
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use function Jaxon\Storage\storage;

storage()->register('sftp-v3', function(string $sRootDir, array $aOptions) {
    $provider = new SftpConnectionProvider(...$aOptions);
    return new SftpAdapter($provider, $sRootDir);
});
```
