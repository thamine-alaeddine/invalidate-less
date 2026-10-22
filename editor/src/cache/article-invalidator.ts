import { Article } from '../articles/article';

export const ARTICLE_INVALIDATOR = Symbol('ArticleInvalidator');

export interface ArticleInvalidator {
  articleUpdated(article: Article): Promise<void>;
  articlePublished(article: Article): Promise<void>;
}
