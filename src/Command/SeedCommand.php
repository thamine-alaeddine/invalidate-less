<?php

namespace App\Command;

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
    private const FIRST_NAMES = ['Alice', 'Bruno', 'Chloé', 'David', 'Emma', 'Farid', 'Giulia', 'Hugo', 'Inès', 'Jonas', 'Karim', 'Léa', 'Malik', 'Nina', 'Omar', 'Paula', 'Quentin', 'Rosa', 'Sami', 'Tess'];
    private const LAST_NAMES = ['Martin', 'Bernard', 'Dubois', 'Garcia', 'Haddad', 'Leroy', 'Moreau', 'Nguyen', 'Petit', 'Rossi', 'Schmidt', 'Silva', 'Thomas', 'Weber', 'Yilmaz'];
    private const TOPICS = ['Politics', 'Economy', 'Science', 'Health', 'Sports', 'Culture', 'Technology', 'Climate', 'Education', 'Travel', 'Food', 'Music', 'Cinema', 'Books', 'Business', 'World', 'Cities', 'Space', 'Energy', 'Law'];
    private const WORDS = ['city', 'council', 'market', 'report', 'study', 'record', 'plan', 'river', 'vote', 'team', 'festival', 'budget', 'school', 'storm', 'launch', 'deal', 'court', 'harbour', 'museum', 'network', 'project', 'summit', 'trial', 'season', 'crisis', 'reform', 'startup', 'station', 'border', 'harvest'];
    private const VERBS = ['approves', 'rejects', 'reveals', 'delays', 'expands', 'questions', 'celebrates', 'faces', 'targets', 'announces'];

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
            $name = self::TOPICS[$i % \count(self::TOPICS)].($i >= \count(self::TOPICS) ? ' '.($i + 1) : '');
            $categoryEntities[] = $category = new Category($this->slugify($name), $name);
            $this->em->persist($category);
        }

        $authorEntities = [];
        for ($i = 0; $i < $authors; ++$i) {
            $name = $this->pick($random, self::FIRST_NAMES).' '.$this->pick($random, self::LAST_NAMES);
            $authorEntities[] = $author = new Author($this->slugify($name).'-'.($i + 1), $name);
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
            $title = ucfirst($this->pick($random, self::WORDS)).' '.$this->pick($random, self::VERBS).' '.$this->pick($random, self::WORDS).' '.$this->pick($random, self::WORDS);
            $body = implode("\n\n", array_map(fn () => $this->sentence($random), range(1, 4)));
            $publishedAt = $publishedAt->modify('-'.$random->getInt(10, 120).' minutes');

            $this->em->persist(new Article(
                $this->slugify($title).'-'.$i,
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

    /**
     * @param list<string> $list
     */
    private function pick(Randomizer $random, array $list): string
    {
        return $list[$random->getInt(0, \count($list) - 1)];
    }

    private function sentence(Randomizer $random): string
    {
        $words = array_map(fn () => $this->pick($random, self::WORDS), range(1, $random->getInt(8, 16)));

        return ucfirst(implode(' ', $words)).'.';
    }

    private function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
