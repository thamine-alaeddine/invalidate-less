<?php

namespace App\Command;

use App\Editor\ArticleEditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:article:publish', description: 'Publish a new article and invalidate the listings it enters')]
final class PublishArticleCommand
{
    public function __construct(private readonly ArticleEditor $editor)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option] ?int $category = null, #[Option] ?int $author = null): int
    {
        $article = $this->editor->publish($category, $author);
        if (null === $article) {
            $io->error('Category or author not found.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Published article %d: /article/%s', $article->getId(), $article->getSlug()));

        return Command::SUCCESS;
    }
}
