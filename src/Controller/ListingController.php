<?php

namespace App\Controller;

use App\Cache\CacheTags;
use App\Cache\CacheTtl;
use App\Entity\Article;
use App\Entity\Author;
use App\Entity\Category;
use App\Repository\ArticleRepository;
use FOS\HttpCache\ResponseTagger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

// Automatic listings rendered as ESI fragments: each one is cached and tagged on its own in Varnish.
#[Route('/_esi')]
#[Cache(public: true, maxage: 0)]
final class ListingController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ResponseTagger $tagger,
        private readonly CacheTtl $ttl,
    ) {
    }

    #[Route('/latest', name: 'esi_latest')]
    public function latest(): Response
    {
        return $this->listing($this->articles->findLatest(20), CacheTags::latestList());
    }

    #[Route('/category/{id}', name: 'esi_category', requirements: ['id' => '\d+'])]
    public function category(Category $category): Response
    {
        return $this->listing($this->articles->findLatestByCategory($category, 20), CacheTags::categoryList($category->getId()));
    }

    #[Route('/author/{id}', name: 'esi_author', requirements: ['id' => '\d+'])]
    public function author(Author $author): Response
    {
        return $this->listing($this->articles->findLatestByAuthor($author, 20), CacheTags::authorList($author->getId()));
    }

    #[Route('/category/{id}/related/{exclude}', name: 'esi_related', requirements: ['id' => '\d+', 'exclude' => '\d+'])]
    public function related(Category $category, int $exclude): Response
    {
        $related = array_slice(array_values(array_filter(
            $this->articles->findLatestByCategory($category, 6),
            static fn (Article $article) => $article->getId() !== $exclude,
        )), 0, 5);

        return $this->listing($related, CacheTags::categoryList($category->getId()));
    }

    /**
     * @param list<Article> $articles
     */
    private function listing(array $articles, string $listTag): Response
    {
        $this->tagger->addTags([$listTag, ...CacheTags::articles(...$articles)]);

        return $this->render('_listing.html.twig', ['articles' => $articles])->setSharedMaxAge($this->ttl->listing());
    }
}
