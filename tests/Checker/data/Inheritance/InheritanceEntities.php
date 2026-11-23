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
