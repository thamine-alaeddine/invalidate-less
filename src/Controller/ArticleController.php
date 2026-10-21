<?php

namespace App\Controller;

use App\Cache\CacheTags;
use App\Cache\CacheTtl;
use App\Entity\Article;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/article/{slug}', name: 'article')]
    #[Cache(public: true, maxage: 60)]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Article $article, ResponseTagger $tagger, CacheTtl $ttl): Response
    {
        // The "More in …" list is an ESI fragment with its own tags.
        $tagger->addTags(CacheTags::articles($article));

        return $this->render('article.html.twig', ['article' => $article])->setSharedMaxAge($ttl->article());
    }
}
