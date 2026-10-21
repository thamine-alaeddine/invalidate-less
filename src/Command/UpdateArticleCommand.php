<?php

namespace App\Command;

use App\Editor\ArticleEditor;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:article:update', description: 'Update one article and invalidate the cache')]
final class UpdateArticleCommand
{
    public function __construct(private readonly ArticleEditor $editor)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument] int $id): int
    {
        $article = $this->editor->update($id);
        if (null === $article) {
            $io->error(\sprintf('Article %d not found.', $id));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Article %d is now at revision %d.', $id, $article->getRevision()));

        return Command::SUCCESS;
    }
}
