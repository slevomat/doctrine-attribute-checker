<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

enum FixtureStatus: string
{

	case Active = 'active';

}

#[ORM\Embeddable]
class FixtureAddress
{

	#[ORM\Column(length: 80)]
	public string $city;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_target')]
class FixtureTargetEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\ManyToOne(targetEntity: FixtureAssociationEntity::class)]
	#[ORM\JoinColumn(name: 'association_id')]
	public ?object $parent = null;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_composite_target')]
class FixtureCompositeTargetEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Id]
	#[ORM\Column]
	public int $sequence;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_composite_association')]
class FixtureCompositeAssociationEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\ManyToOne(targetEntity: FixtureCompositeTargetEntity::class)]
	#[ORM\JoinColumn(name: 'target_id', referencedColumnName: 'id')]
	#[ORM\JoinColumn(name: 'target_sequence', referencedColumnName: 'sequence')]
	public FixtureCompositeTargetEntity $target;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_basic')]
class FixtureBasicEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\Column(length: 60)]
	public string $name;

	#[ORM\Column(type: 'json')]
	public array $bannedDates;

	#[ORM\Column]
	public FixtureStatus $status;

	#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
	public string $amount;

	#[ORM\Embedded]
	public FixtureAddress $address;

	public function callback(): void
	{
	}

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_boolean')]
class FixtureBooleanEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\Column]
	public bool $enabled;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_association')]
class FixtureAssociationEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]
	#[ORM\JoinColumn(name: 'target_id')]
	public ?object $target = null;

	#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]
	#[ORM\JoinColumn(name: 'single_id')]
	public object $single;

	/**
	 * @var Collection<int, FixtureTargetEntity>
	 */
	#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]
	public Collection $children;

	/**
	 * @var Collection<int, FixtureTargetEntity>
	 */
	#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]
	#[ORM\JoinColumn(name: 'attribute_check_association_id')]
	#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]
	#[ORM\JoinTable(name: 'attribute_check_association_target')]
	public Collection $targets;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_typed_association')]
class FixtureTypedAssociationEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]
	#[ORM\JoinColumn(name: 'target_id')]
	public FixtureTargetEntity $target;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_typed_one_to_one')]
class FixtureTypedOneToOneEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]
	#[ORM\JoinColumn(name: 'single_id')]
	public ?FixtureTargetEntity $single = null;

}

#[ORM\Entity]
#[ORM\Table(name: 'attribute_check_reserved')]
class FixtureReservedEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\Column]
	public string $order;

}
