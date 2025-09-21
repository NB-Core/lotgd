<?php declare(strict_types = 1);

namespace PHPStan\Type\Doctrine\Descriptors;

use Doctrine\DBAL\Connection;
use PHPStan\Doctrine\Driver\DriverDetector;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function in_array;

class DecimalType implements DoctrineTypeDescriptor, DoctrineTypeDriverAwareDescriptor
{

	/** @var DriverDetector */
	private $driverDetector;

	public function __construct(DriverDetector $driverDetector)
	{
		$this->driverDetector = $driverDetector;
	}

	public function getType(): string
	{
		return \Doctrine\DBAL\Types\DecimalType::class;
	}

	public function getWritableToPropertyType(): Type
	{
		return (new FloatType())->toString();
	}

	public function getWritableToDatabaseType(): Type
	{
		return TypeCombinator::union(new StringType(), new FloatType(), new IntegerType());
	}

	public function getDatabaseInternalType(): Type
	{
		return TypeCombinator::union(new FloatType(), new IntegerType());
	}

	public function getDatabaseInternalTypeForDriver(Connection $connection): Type
	{
		$driverType = $this->driverDetector->detect($connection);

		if ($driverType === DriverDetector::SQLITE3 || $driverType === DriverDetector::PDO_SQLITE) {
			return TypeCombinator::union(new FloatType(), new IntegerType());
		}

		if (in_array($driverType, [
			DriverDetector::MYSQLI,
			DriverDetector::PDO_MYSQL,
			DriverDetector::PGSQL,
			DriverDetector::PDO_PGSQL,
		], true)) {
			return (new FloatType())->toString();
		}

		// not yet supported driver, return the old implementation guess
		return $this->getDatabaseInternalType();
	}

}
