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

    /**
     * Media (optional) – Pfad unter /public, z.B. guides/solution-12/step-99/step.pdf
     */
    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'media_path', type: 'string', length: 255, nullable: true)]
    private ?string $mediaPath = null;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'media_original_name', type: 'string', length: 255, nullable: true)]
    private ?string $mediaOriginalName = null;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'media_mime_type', type: 'string', length: 100, nullable: true)]
    private ?string $mediaMimeType = null;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'media_size', type: 'integer', nullable: true)]
    private ?int $mediaSize = null;

    #[Groups(['solution:read'])]
    #[ORM\Column(name: 'media_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $mediaUpdatedAt = null;

    /**
     * Bequeme URL für Frontend (nur Anzeige)
     */
    #[Groups(['solution:read'])]
    #[ApiProperty(readable: true, writable: false)]
    public function getMediaUrl(): ?string
    {
        if (!$this->mediaPath) {
            return null;
        }
        return '/' . ltrim($this->mediaPath, '/');
    }

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

    public function getMediaPath(): ?string
    {
        return $this->mediaPath;
    }

    public function setMediaPath(?string $mediaPath): self
    {
        $this->mediaPath = $mediaPath;
        return $this;
    }

    public function getMediaOriginalName(): ?string
    {
        return $this->mediaOriginalName;
    }

    public function setMediaOriginalName(?string $mediaOriginalName): self
    {
        $this->mediaOriginalName = $mediaOriginalName;
        return $this;
    }

    public function getMediaMimeType(): ?string
    {
        return $this->mediaMimeType;
    }

    public function setMediaMimeType(?string $mediaMimeType): self
    {
        $this->mediaMimeType = $mediaMimeType;
        return $this;
    }

    public function getMediaSize(): ?int
    {
        return $this->mediaSize;
    }

    public function setMediaSize(?int $mediaSize): self
    {
        $this->mediaSize = $mediaSize;
        return $this;
    }

    public function getMediaUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->mediaUpdatedAt;
    }

    public function setMediaUpdatedAt(?\DateTimeImmutable $mediaUpdatedAt): self
    {
        $this->mediaUpdatedAt = $mediaUpdatedAt;
        return $this;
    }

    public function clearMedia(): self
    {
        $this->mediaPath = null;
        $this->mediaOriginalName = null;
        $this->mediaMimeType = null;
        $this->mediaSize = null;
        $this->mediaUpdatedAt = null;
        return $this;
    }
}
