<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\SupportSolutionKeywordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

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
#[ORM\Entity(repositoryClass: SupportSolutionKeywordRepository::class)]
#[ORM\Table(name: 'support_solution_keyword')]
#[ORM\UniqueConstraint(name: 'uniq_solution_keyword', columns: ['solution_id', 'keyword'])]
#[UniqueEntity(fields: ['solution', 'keyword'], message: 'Dieses Keyword existiert für diese Solution bereits.')]
class SupportSolutionKeyword
{
    #[Groups(['solution:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    /**
     * Wichtig für API Platform:
     * - in write, damit du bei POST/PATCH die Solution per IRI setzen kannst:
     *   "solution": "/api/support_solutions/1"
     */
    #[Groups(['solution:read', 'solution:write'])]
    #[ORM\ManyToOne(targetEntity: SupportSolution::class, inversedBy: 'keywords')]
    #[ORM\JoinColumn(name: 'solution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?SupportSolution $solution = null;

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[ORM\Column(length: 80)]
    private string $keyword = '';

    #[Groups(['solution:read', 'solution:write'])]
    #[Assert\Range(min: 1, max: 10)]
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    private int $weight = 1;

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

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): self
    {
        $this->keyword = mb_strtolower(trim($keyword));

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): self
    {
        // du hast bereits clamp – Range übernimmt Validierung, clamp ist trotzdem ok:
        $this->weight = max(1, min(10, $weight));

        return $this;
    }
}
