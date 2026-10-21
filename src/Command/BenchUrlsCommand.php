<?php

namespace App\Command;

use App\Repository\ArticleRepository;
use App\Repository\AuthorRepository;
use App\Repository\CategoryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bench:urls', description: 'Export the URLs and article ids the k6 scenario browses and edits')]
final class BenchUrlsCommand
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CategoryRepository $categories,
        private readonly AuthorRepository $authors,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option] string $output = 'bench/urls.json'): int
    {
        // Articles newest first, so the scenario can favour recent ones like real readers do.
        $articles = $this->articles->findBy([], ['publishedAt' => 'DESC']);

        $data = [
            'categories' => array_map(static fn ($c) => '/category/'.$c->getSlug(), $this->categories->findBy([], ['id' => 'ASC'])),
            'authors' => array_map(static fn ($a) => '/author/'.$a->getSlug(), $this->authors->findBy([], ['id' => 'ASC'])),
            'articles' => array_map(static fn ($a) => '/article/'.$a->getSlug(), $articles),
            'articleIds' => array_map(static fn ($a) => $a->getId(), $articles),
        ];

        file_put_contents($output, json_encode($data, \JSON_UNESCAPED_SLASHES));
        $io->success(\sprintf('Wrote %d articles, %d categories and %d authors to %s.', \count($data['articles']), \count($data['categories']), \count($data['authors']), $output));

        return Command::SUCCESS;
    }
}
