<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data\NullableEmbedded;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class NullableEmbeddedAddress
{

	#[ORM\Column(length: 80)]
	public string $city;

}

#[ORM\Embeddable]
class NullableEmbeddedContact
{

	#[ORM\Embedded]
	public NullableEmbeddedAddress $address;

}

#[ORM\Entity]
#[ORM\Table(name: 'nullable_embedded_required')]
class NullableEmbeddedRequiredEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Embedded]
	public NullableEmbeddedAddress $address;

}

#[ORM\Entity]
#[ORM\Table(name: 'nullable_embedded_optional')]
class NullableEmbeddedOptionalEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Embedded]
	public ?NullableEmbeddedAddress $address = null;

}

#[ORM\Entity]
#[ORM\Table(name: 'nullable_embedded_nested')]
class NullableEmbeddedNestedEntity
{

	#[ORM\Id]
	#[ORM\Column]
	public int $id;

	#[ORM\Embedded]
	public ?NullableEmbeddedContact $contact = null;

}
