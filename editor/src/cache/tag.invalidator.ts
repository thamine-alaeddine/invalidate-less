import { Injectable } from '@nestjs/common';
import { Article } from '../articles/article';
import { ArticleInvalidator } from './article-invalidator';
import { CacheTags } from './cache-tags';
import { VarnishClient } from './varnish.client';

// Strategy B: purge only what is tagged with the change (Varnish xkey).
@Injectable()
export class TagInvalidator implements ArticleInvalidator {
  constructor(private readonly varnish: VarnishClient) {}

  // The article page and every listing fragment that shows it.
  articleUpdated(article: Article): Promise<void> {
    return this.varnish.purgeTags([CacheTags.article(article.id)]);
  }

  // Automatic listings the new article now enters.
  articlePublished(article: Article): Promise<void> {
    return this.varnish.purgeTags([
      CacheTags.latestList(),
      CacheTags.categoryList(article.categoryId),
      CacheTags.authorList(article.authorId),
    ]);
  }
}
