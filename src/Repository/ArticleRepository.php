<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Author;
use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
final class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * @return list<Article>
     */
    public function findLatest(int $limit): array
    {
        return $this->latest($limit)->getQuery()->getResult();
    }

    /**
     * @return list<Article>
     */
    public function findLatestByCategory(Category $category, int $limit): array
    {
        return $this->latest($limit)
            ->andWhere('a.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Article>
     */
    public function findLatestByAuthor(Author $author, int $limit): array
    {
        return $this->latest($limit)
            ->andWhere('a.author = :author')
            ->setParameter('author', $author)
            ->getQuery()
            ->getResult();
    }

    // Joins category and author so list rendering does not trigger extra queries.
    private function latest(int $limit): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('c', 'au')
            ->join('a.category', 'c')
            ->join('a.author', 'au')
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit);
    }
}
