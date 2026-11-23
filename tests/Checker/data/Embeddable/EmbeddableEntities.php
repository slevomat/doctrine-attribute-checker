<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data\Embeddable;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class EmbeddableAddress
{

	#[ORM\Column(length: 80)]
	public string $city;

	#[ORM\Column(length: 10, nullable: true)]
	public ?string $zip = null;

}

#[ORM\Entity]
#[ORM\Table(name: 'embeddable_home')]
class EmbeddableHomeEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Embedded]
	public EmbeddableAddress $address;

}

#[ORM\Entity]
#[ORM\Table(name: 'embeddable_office')]
class EmbeddableOfficeEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Embedded(columnPrefix: 'billing_')]
	public EmbeddableAddress $billing;

}
