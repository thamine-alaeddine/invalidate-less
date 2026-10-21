<?php

namespace App\Controller;

use App\Editor\ArticleEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EditorController extends AbstractController
{
    #[Route('/editor/articles/{id}', name: 'editor_article_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, ArticleEditor $editor): JsonResponse
    {
        $article = $editor->update($id) ?? throw new NotFoundHttpException(\sprintf('Article %d not found.', $id));

        return $this->json(['id' => $article->getId(), 'revision' => $article->getRevision()]);
    }
}
