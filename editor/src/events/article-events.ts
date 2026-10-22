import { Article } from '../articles/article';

export const ARTICLE_EVENTS = Symbol('ArticleEvents');

// Tells the rest of the system an article changed; resolves once the event is queued, not once caches are purged.
export interface ArticleEvents {
  updated(article: Article): Promise<void>;
  published(article: Article): Promise<void>;
}
