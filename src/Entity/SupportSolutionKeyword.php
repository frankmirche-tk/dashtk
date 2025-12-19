<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'support_solution_keyword')]
#[ORM\UniqueConstraint(name: 'uniq_solution_keyword', columns: ['solution_id', 'keyword'])]
class SupportSolutionKeyword
{
    #[Groups(['solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: SupportSolution::class, inversedBy: 'keywords')]
    #[ORM\JoinColumn(name: 'solution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupportSolution $solution;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[ORM\Column(length: 80)]
    private string $keyword;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    private int $weight = 1;

    public function getId(): ?string { return $this->id; }

    public function getSolution(): SupportSolution { return $this->solution; }
    public function setSolution(SupportSolution $solution): self { $this->solution = $solution; return $this; }

    public function getKeyword(): string { return $this->keyword; }
    public function setKeyword(string $keyword): self
    {
        $this->keyword = mb_strtolower(trim($keyword));
        return $this;
    }

    public function getWeight(): int { return $this->weight; }
    public function setWeight(int $weight): self
    {
        $this->weight = max(1, min(10, $weight));
        return $this;
    }
}
