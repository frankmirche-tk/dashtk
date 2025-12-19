<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(),
        new Put(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['solution:read']],
    denormalizationContext: ['groups' => ['solution:write']]
)]

#[ORM\Entity]
#[ORM\Table(name: 'support_solution_step')]
#[ORM\UniqueConstraint(name: 'uniq_solution_step', columns: ['solution_id', 'step_no'])]
class SupportSolutionStep
{
    #[Groups(['solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: SupportSolution::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'solution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupportSolution $solution;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Positive]
    #[ORM\Column(name: 'step_no', type: 'integer')]
    private int $stepNo;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $instruction;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedResult = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nextIfFailed = null;

    public function getId(): ?string { return $this->id; }

    public function getSolution(): SupportSolution { return $this->solution; }
    public function setSolution(SupportSolution $solution): self { $this->solution = $solution; return $this; }

    public function getStepNo(): int { return $this->stepNo; }
    public function setStepNo(int $stepNo): self { $this->stepNo = $stepNo; return $this; }

    public function getInstruction(): string { return $this->instruction; }
    public function setInstruction(string $instruction): self { $this->instruction = $instruction; return $this; }

    public function getExpectedResult(): ?string { return $this->expectedResult; }
    public function setExpectedResult(?string $expectedResult): self { $this->expectedResult = $expectedResult; return $this; }

    public function getNextIfFailed(): ?string { return $this->nextIfFailed; }
    public function setNextIfFailed(?string $nextIfFailed): self { $this->nextIfFailed = $nextIfFailed; return $this; }
}
