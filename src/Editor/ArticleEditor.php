<?php

namespace App\Editor;

use App\Cache\ArticleInvalidator;
use App\Content\TextGenerator;
use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Repository\AuthorRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\ClockInterface;

// Invalidation runs synchronously in every action so callers only return once caches are purged.
final class ArticleEditor
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CategoryRepository $categories,
        private readonly AuthorRepository $authors,
        private readonly EntityManagerInterface $em,
        private readonly ArticleInvalidator $invalidator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function update(int $id): ?Article
    {
        $article = $this->articles->find($id);
        if (null === $article) {
            return null;
        }

        $article->edit($this->clock->now());
        $this->em->flush();
        $this->invalidator->articleUpdated($article);

        return $article;
    }

    // Category and author default to a round-robin on the article number, so runs stay reproducible.
    public function publish(?int $categoryId = null, ?int $authorId = null): ?Article
    {
        $number = $this->articles->count() + 1;
        $category = null !== $categoryId ? $this->categories->find($categoryId) : $this->roundRobin($this->categories->findBy([], ['id' => 'ASC']), $number);
        $author = null !== $authorId ? $this->authors->find($authorId) : $this->roundRobin($this->authors->findBy([], ['id' => 'ASC']), $number);
        if (null === $category || null === $author) {
            return null;
        }

        $random = new Randomizer(new Mt19937($number));
        $title = TextGenerator::title($random);
        $article = new Article(
            TextGenerator::slugify($title).'-'.$number,
            $title,
            TextGenerator::body($random),
            $this->clock->now(),
            $category,
            $author,
        );

        $this->em->persist($article);
        $this->em->flush();
        $this->invalidator->articlePublished($article);

        return $article;
    }

    /**
     * @template T of object
     *
     * @param list<T> $items
     *
     * @return T|null
     */
    private function roundRobin(array $items, int $number): ?object
    {
        return [] === $items ? null : $items[$number % \count($items)];
    }
}
