<?php

namespace App\Controller;

use App\Cache\ArticleTags;
use App\Entity\Author;
use App\Repository\ArticleRepository;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController extends AbstractController
{
    #[Route('/author/{slug}', name: 'author')]
    #[Cache(public: true, maxage: 60, smaxage: 86400)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Author $author, ArticleRepository $articles, ResponseTagger $tagger): Response
    {
        $latest = $articles->findLatestByAuthor($author, 20);
        $tagger->addTags(ArticleTags::of(...$latest));

        return $this->render('author.html.twig', [
            'author' => $author,
            'articles' => $latest,
        ]);
    }
}
