<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data\Inheritance;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class InheritanceBaseEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

	#[ORM\ManyToOne]
	public ?InheritanceTargetEntity $owner = null;

}

#[ORM\Entity]
#[ORM\Table(name: 'inheritance_target')]
class InheritanceTargetEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

}

#[ORM\Entity]
#[ORM\Table(name: 'inheritance_first')]
class InheritanceFirstEntity extends InheritanceBaseEntity
{

}

#[ORM\Entity]
#[ORM\Table(name: 'inheritance_second')]
class InheritanceSecondEntity extends InheritanceBaseEntity
{

}

#[ORM\Entity]
#[ORM\Table(name: 'inheritance_vehicle')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type')]
#[ORM\DiscriminatorMap(['car' => InheritanceCarEntity::class, 'bike' => InheritanceBikeEntity::class])]
abstract class InheritanceVehicleEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

	#[ORM\Column(length: 20)]
	public string $name;

}

#[ORM\Entity]
class InheritanceCarEntity extends InheritanceVehicleEntity
{

	#[ORM\Column(length: 10)]
	public string $plate;

	#[ORM\Column(length: 10, nullable: true)]
	public ?string $color = null;

	#[ORM\ManyToOne]
	#[ORM\JoinColumn(nullable: false)]
	public InheritanceTargetEntity $driver;

}

#[ORM\Entity]
class InheritanceBikeEntity extends InheritanceVehicleEntity
{

}
