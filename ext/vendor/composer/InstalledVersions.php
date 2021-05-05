<?php











namespace Composer;

use Composer\Semver\VersionParser;






class InstalledVersions
{
private static $installed = array (
  'root' => 
  array (
    'pretty_version' => 'dev-master',
    'version' => 'dev-master',
    'aliases' => 
    array (
    ),
    'reference' => '0a66380422295c1ae4548c319d959a4d4e0fb44f',
    'name' => '__root__',
  ),
  'versions' => 
  array (
    '__root__' => 
    array (
      'pretty_version' => 'dev-master',
      'version' => 'dev-master',
      'aliases' => 
      array (
      ),
      'reference' => '0a66380422295c1ae4548c319d959a4d4e0fb44f',
    ),
    'aura/session' => 
    array (
      'pretty_version' => '2.1.0',
      'version' => '2.1.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '7d2f7d41ad693970b5b6b83facca0961d3378883',
    ),
    'jaxon-php/jaxon-armada' => 
    array (
      'pretty_version' => 'v2.0.1',
      'version' => '2.0.1.0',
      'aliases' => 
      array (
      ),
      'reference' => '20b8557fe2703fa35acaea671cbf52ed36955ee8',
    ),
    'jaxon-php/jaxon-core' => 
    array (
      'pretty_version' => 'v2.2.16',
      'version' => '2.2.16.0',
      'aliases' => 
      array (
      ),
      'reference' => '386b24725ec28ca29280b15e0f0895a910aeed3a',
    ),
    'jaxon-php/jaxon-sentry' => 
    array (
      'pretty_version' => 'v2.0.7',
      'version' => '2.0.7.0',
      'aliases' => 
      array (
      ),
      'reference' => 'ab768cc2fe5c636c4e403f1288c83899ed1ffa1b',
    ),
    'lemonphp/event' => 
    array (
      'pretty_version' => 'v1.0.0',
      'version' => '1.0.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '57d9ffcc551febca967068668dd9f005d989d94b',
    ),
    'matthiasmullie/minify' => 
    array (
      'pretty_version' => '1.3.66',
      'version' => '1.3.66.0',
      'aliases' => 
      array (
      ),
      'reference' => '45fd3b0f1dfa2c965857c6d4a470bea52adc31a6',
    ),
    'matthiasmullie/path-converter' => 
    array (
      'pretty_version' => '1.1.3',
      'version' => '1.1.3.0',
      'aliases' => 
      array (
      ),
      'reference' => 'e7d13b2c7e2f2268e1424aaed02085518afa02d9',
    ),
    'phpmailer/phpmailer' => 
    array (
      'pretty_version' => 'v6.4.1',
      'version' => '6.4.1.0',
      'aliases' => 
      array (
      ),
      'reference' => '9256f12d8fb0cd0500f93b19e18c356906cbed3d',
    ),
    'pimple/pimple' => 
    array (
      'pretty_version' => 'v3.0.2',
      'version' => '3.0.2.0',
      'aliases' => 
      array (
      ),
      'reference' => 'a30f7d6e57565a2e1a316e1baf2a483f788b258a',
    ),
  ),
);







public static function getInstalledPackages()
{
return array_keys(self::$installed['versions']);
}









public static function isInstalled($packageName)
{
return isset(self::$installed['versions'][$packageName]);
}














public static function satisfies(VersionParser $parser, $packageName, $constraint)
{
$constraint = $parser->parseConstraints($constraint);
$provided = $parser->parseConstraints(self::getVersionRanges($packageName));

return $provided->matches($constraint);
}










public static function getVersionRanges($packageName)
{
if (!isset(self::$installed['versions'][$packageName])) {
throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}

$ranges = array();
if (isset(self::$installed['versions'][$packageName]['pretty_version'])) {
$ranges[] = self::$installed['versions'][$packageName]['pretty_version'];
}
if (array_key_exists('aliases', self::$installed['versions'][$packageName])) {
$ranges = array_merge($ranges, self::$installed['versions'][$packageName]['aliases']);
}
if (array_key_exists('replaced', self::$installed['versions'][$packageName])) {
$ranges = array_merge($ranges, self::$installed['versions'][$packageName]['replaced']);
}
if (array_key_exists('provided', self::$installed['versions'][$packageName])) {
$ranges = array_merge($ranges, self::$installed['versions'][$packageName]['provided']);
}

return implode(' || ', $ranges);
}





public static function getVersion($packageName)
{
if (!isset(self::$installed['versions'][$packageName])) {
throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}

if (!isset(self::$installed['versions'][$packageName]['version'])) {
return null;
}

return self::$installed['versions'][$packageName]['version'];
}





public static function getPrettyVersion($packageName)
{
if (!isset(self::$installed['versions'][$packageName])) {
throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}

if (!isset(self::$installed['versions'][$packageName]['pretty_version'])) {
return null;
}

return self::$installed['versions'][$packageName]['pretty_version'];
}





public static function getReference($packageName)
{
if (!isset(self::$installed['versions'][$packageName])) {
throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}

if (!isset(self::$installed['versions'][$packageName]['reference'])) {
return null;
}

return self::$installed['versions'][$packageName]['reference'];
}





public static function getRootPackage()
{
return self::$installed['root'];
}







public static function getRawData()
{
return self::$installed;
}



















public static function reload($data)
{
self::$installed = $data;
}
}
