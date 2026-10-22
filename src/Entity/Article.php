<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Index(columns: ['published_at'])]
#[ORM\Index(columns: ['category_id', 'published_at'])]
#[ORM\Index(columns: ['author_id', 'published_at'])]
class Article
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private int $revision = 1;

    public function __construct(
        #[ORM\Column(length: 200, unique: true)]
        private string $slug,
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column]
        private \DateTimeImmutable $publishedAt,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
        private Category $category,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
        private Author $author,
    ) {
        $this->updatedAt = $publishedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }
}
