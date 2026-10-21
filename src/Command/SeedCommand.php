<?php

namespace App\Command;

use App\Content\TextGenerator;
use App\Entity\Article;
use App\Entity\Author;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed', description: 'Recreate the schema and seed deterministic content')]
final class SeedCommand
{
    private const BATCH_SIZE = 500;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option] int $articles = 5000,
        #[Option] int $categories = 20,
        #[Option] int $authors = 100,
        #[Option] int $seed = 42,
    ): int {
        $random = new Randomizer(new Mt19937($seed));

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $categoryEntities = [];
        for ($i = 0; $i < $categories; ++$i) {
            $name = TextGenerator::categoryName($i);
            $categoryEntities[] = $category = new Category(TextGenerator::slugify($name), $name);
            $this->em->persist($category);
        }

        $authorEntities = [];
        for ($i = 0; $i < $authors; ++$i) {
            $name = TextGenerator::personName($random);
            $authorEntities[] = $author = new Author(TextGenerator::slugify($name).'-'.($i + 1), $name);
            $this->em->persist($author);
        }

        $this->em->flush();
        $categoryIds = array_map(static fn (Category $c) => $c->getId(), $categoryEntities);
        $authorIds = array_map(static fn (Author $a) => $a->getId(), $authorEntities);
        $this->em->clear();

        // Fixed base date so every run produces identical data.
        $publishedAt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $io->progressStart($articles);
        for ($i = 1; $i <= $articles; ++$i) {
            $title = TextGenerator::title($random);
            $body = TextGenerator::body($random);
            $publishedAt = $publishedAt->modify('-'.$random->getInt(10, 120).' minutes');

            $this->em->persist(new Article(
                TextGenerator::slugify($title).'-'.$i,
                $title,
                $body,
                $publishedAt,
                $this->em->getReference(Category::class, $categoryIds[$random->getInt(0, \count($categoryIds) - 1)]),
                $this->em->getReference(Author::class, $authorIds[$random->getInt(0, \count($authorIds) - 1)]),
            ));

            if (0 === $i % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
            }
            $io->progressAdvance();
        }
        $this->em->flush();
        $this->em->clear();
        $io->progressFinish();

        $io->success(\sprintf('Seeded %d categories, %d authors and %d articles (seed %d).', $categories, $authors, $articles, $seed));

        return 0;
    }
}
