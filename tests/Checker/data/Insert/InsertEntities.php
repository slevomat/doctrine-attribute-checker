<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data\Insert;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'insert_article')]
class InsertArticleEntity
{

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	public int $id;

	#[ORM\Column(length: 20)]
	public string $title;

	#[ORM\ManyToOne]
	public ?InsertTagEntity $mainTag = null;

	/** @var Collection<int, InsertTagEntity> */
	#[ORM\ManyToMany(targetEntity: InsertTagEntity::class)]
	#[ORM\JoinTable(name: 'insert_article_tag')]
	public Collection $tags;

	public function __construct()
	{
		$this->tags = new ArrayCollection();
	}

}

#[ORM\Entity]
#[ORM\Table(name: 'insert_tag')]
class InsertTagEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

}

#[ORM\Entity]
#[ORM\Table(name: 'insert_vehicle')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', length: 20)]
#[ORM\DiscriminatorMap(['car' => InsertCarEntity::class, 'bike' => InsertBikeEntity::class])]
abstract class InsertVehicleEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

}

#[ORM\Entity]
class InsertCarEntity extends InsertVehicleEntity
{

	#[ORM\Column(length: 10)]
	public string $plate;

}

#[ORM\Entity]
class InsertBikeEntity extends InsertVehicleEntity
{

}
