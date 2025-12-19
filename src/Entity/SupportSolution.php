<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    denormalizationContext: ['groups' => ['solution:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'title' => 'partial',
    'symptoms' => 'partial',
    'contextNotes' => 'partial',
    'keywords.keyword' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
#[ApiFilter(OrderFilter::class, properties: ['priority', 'createdAt', 'updatedAt'], arguments: ['orderParameterName' => 'order'])]
#[ORM\Entity]
#[ORM\Table(name: 'support_solution')]
class SupportSolution
{
    #[Groups(['solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $title;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $symptoms;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(name: 'context_notes', type: 'text', nullable: true)]
    private ?string $contextNotes = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SupportSolutionKeyword> */
    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Valid]
    #[ORM\OneToMany(mappedBy: 'solution', targetEntity: SupportSolutionKeyword::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $keywords;

    /** @var Collection<int, SupportSolutionStep> */
    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Valid]
    #[ORM\OneToMany(mappedBy: 'solution', targetEntity: SupportSolutionStep::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stepNo' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->keywords = new ArrayCollection();
        $this->steps = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getSymptoms(): string { return $this->symptoms; }
    public function setSymptoms(string $symptoms): self { $this->symptoms = $symptoms; return $this; }

    public function getContextNotes(): ?string { return $this->contextNotes; }
    public function setContextNotes(?string $contextNotes): self { $this->contextNotes = $contextNotes; return $this; }

    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): self { $this->priority = $priority; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, SupportSolutionKeyword> */
    public function getKeywords(): Collection { return $this->keywords; }

    /** @return Collection<int, SupportSolutionStep> */
    public function getSteps(): Collection { return $this->steps; }

    public function addKeyword(SupportSolutionKeyword $keyword): self
    {
        if (!$this->keywords->contains($keyword)) {
            $this->keywords->add($keyword);
            $keyword->setSolution($this);
        }
        return $this;
    }

    public function removeKeyword(SupportSolutionKeyword $keyword): self
    {
        if ($this->keywords->removeElement($keyword)) {
            // orphanRemoval=true -> DB löscht
        }
        return $this;
    }

    public function addStep(SupportSolutionStep $step): self
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setSolution($this);
        }
        return $this;
    }

    public function removeStep(SupportSolutionStep $step): self
    {
        $this->steps->removeElement($step);
        return $this;
    }
}
