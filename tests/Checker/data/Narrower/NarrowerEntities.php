<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Data\Narrower;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'narrower')]
class NarrowerEntity
{

	#[ORM\Id]
	#[ORM\Column(length: 36, options: ['fixed' => true])]
	public string $id;

	#[ORM\Column(options: ['unsigned' => true])]
	public int $position;

	#[ORM\Column(length: 20)]
	public string $title;

	#[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
	public string $price;

}
