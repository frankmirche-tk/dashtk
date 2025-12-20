<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\SupportSolutionStepRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['solution:read']],
    denormalizationContext: ['groups' => ['solution:write']],
)]
#[ORM\Entity(repositoryClass: SupportSolutionStepRepository::class)]
#[ORM\Table(name: 'support_solution_step')]
#[ORM\UniqueConstraint(name: 'uniq_solution_step', columns: ['solution_id', 'step_no'])]
#[ApiFilter(SearchFilter::class, properties: ['solution' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['stepNo'])]
class SupportSolutionStep
{
    #[Groups(['solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    /**
     * WICHTIG:
     * - in solution:write, damit POST/PATCH "solution": "/api/support_solutions/12" gesetzt wird
     */
    #[ApiProperty(readableLink: false, writableLink: true)]
    #[Groups(['solution:write'])] // <-- wichtig: NICHT in solution:read
    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: SupportSolution::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'solution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SupportSolution $solution = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Positive]
    #[ORM\Column(name: 'step_no', type: 'integer')]
    private int $stepNo = 1;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $instruction = '';

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedResult = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nextIfFailed = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSolution(): ?SupportSolution
    {
        return $this->solution;
    }

    public function setSolution(?SupportSolution $solution): self
    {
        $this->solution = $solution;
        return $this;
    }

    public function getStepNo(): int
    {
        return $this->stepNo;
    }

    public function setStepNo(int $stepNo): self
    {
        $this->stepNo = $stepNo;
        return $this;
    }

    public function getInstruction(): string
    {
        return $this->instruction;
    }

    public function setInstruction(string $instruction): self
    {
        $this->instruction = $instruction;
        return $this;
    }

    public function getExpectedResult(): ?string
    {
        return $this->expectedResult;
    }

    public function setExpectedResult(?string $expectedResult): self
    {
        $this->expectedResult = $expectedResult;
        return $this;
    }

    public function getNextIfFailed(): ?string
    {
        return $this->nextIfFailed;
    }

    public function setNextIfFailed(?string $nextIfFailed): self
    {
        $this->nextIfFailed = $nextIfFailed;
        return $this;
    }
}
