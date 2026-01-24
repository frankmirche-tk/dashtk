<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\State\SupportSolutionProcessor;
use Symfony\Component\Validator\GroupSequenceProviderInterface;
use Symfony\Component\Validator\Constraints\GroupSequence;



#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['solution:list']]
        ),

        new Get(
            normalizationContext: ['groups' => ['solution:read']]
        ),

        // CREATE
        new Post(
            processor: SupportSolutionProcessor::class,
            denormalizationContext: ['groups' => ['solution:write']],
            normalizationContext: ['groups' => ['solution:read']]
        ),
        new Put(
            processor: SupportSolutionProcessor::class,
            denormalizationContext: ['groups' => ['solution:write']]
        ),
        new Patch(
            processor: SupportSolutionProcessor::class,
            denormalizationContext: ['groups' => ['solution:write']]
        ),

        new Delete(),
    ],
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
#[ORM\HasLifecycleCallbacks]
#[Assert\GroupSequenceProvider]
class SupportSolution implements GroupSequenceProviderInterface
{
    #[Groups(['solution:list', 'solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[Groups(['solution:list', 'solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $title = '';

    #[Groups(['solution:list', 'solution:read', 'solution:write'])]
    #[Assert\NotBlank(groups: ['sop'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $symptoms = null;


    #[Groups(['solution:list', 'solution:read', 'solution:write'])]
    #[ORM\Column(name: 'context_notes', type: 'text', nullable: true)]
    private ?string $contextNotes = null;

    #[Groups(['solution:list', 'solution:read', 'solution:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    #[Groups(['solution:list', 'solution:read', 'solution:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[Groups(['solution:list', 'solution:read'])]
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Groups(['solution:list', 'solution:read'])]
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SupportSolutionKeyword> */
    // ✅ NICHT in solution:list (sonst Join-Hölle)
    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Valid]
    #[ORM\OneToMany(mappedBy: 'solution', targetEntity: SupportSolutionKeyword::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $keywords;

    /** @var Collection<int, SupportSolutionStep> */
    // ✅ NICHT in solution:list (sonst Join-Hölle)
    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Valid]
    #[ORM\OneToMany(mappedBy: 'solution', targetEntity: SupportSolutionStep::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stepNo' => 'ASC'])]
    private Collection $steps;

    /**
     * Defines the solution type.
     *
     * Possible values:
     * - SOP  (step-based solution)
     * - FORM (document / external form)
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'string', length: 16)]
    private string $type = 'SOP';

    /**
     * Indicates how the primary medium is stored.
     *
     * Possible values:
     * - internal (uploaded file)
     * - external (reference only)
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $mediaType = null;

    /**
     * External media provider identifier (e.g. "google_drive").
     *
     * Only set when mediaType = external.
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $externalMediaProvider = null;

    /**
     * External media URL (e.g. Google Drive file URL).
     *
     * Only set when mediaType = external.
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $externalMediaUrl = null;

    /**
     * Optional external file identifier (e.g. Google Drive fileId).
     *
     * Used for previews or future integrations.
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $externalMediaId = null;


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

    public function getSymptoms(): ?string { return $this->symptoms; }
    public function setSymptoms(?string $symptoms): self { $this->symptoms = $symptoms; return $this; }

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
        $this->keywords->removeElement($keyword);
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

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function isForm(): bool
    {
        return $this->type === 'FORM';
    }

    public function getMediaType(): ?string
    {
        return $this->mediaType;
    }

    public function isExternalMedia(): bool
    {
        return $this->mediaType === 'external';
    }

    public function getExternalMediaProvider(): ?string
    {
        return $this->externalMediaProvider;
    }

    public function getExternalMediaUrl(): ?string
    {
        return $this->externalMediaUrl;
    }

    public function getExternalMediaId(): ?string
    {
        return $this->externalMediaId;
    }

    public function getGroupSequence(): GroupSequence|array
    {
        if ($this->type === 'FORM') {
            return new GroupSequence(['Default', 'form']);
        }

        return new GroupSequence(['Default', 'sop']);
    }


}
