import { ArticleEvent } from './article-event';
import { CacheTags } from './cache-tags';

// What to purge in Varnish for one event; no purge at all for the TTL-only strategy.
export type Purge = { kind: 'ban-all' } | { kind: 'tags'; tags: string[] } | { kind: 'none' };

export type Strategy = (event: ArticleEvent) => Purge;

const strategies: Record<string, Strategy> = {
  // A: ban every cached page and fragment, whatever changed.
  full: () => ({ kind: 'ban-all' }),
  // B: the article page and listings showing it on update, the listings it enters on publish.
  tags: ({ type, data }) => ({
    kind: 'tags',
    tags: type === 'article.updated'
      ? [CacheTags.article(data.id)]
      : [CacheTags.latestList(), CacheTags.categoryList(data.categoryId), CacheTags.authorList(data.authorId)],
  }),
  // TTL only: content refreshes when the short TTL expires.
  ttl: () => ({ kind: 'none' }),
};

export function strategyFromEnv(name = process.env.INVALIDATION_STRATEGY ?? 'full'): Strategy {
  const strategy = strategies[name];
  if (!strategy) {
    throw new Error(`Unknown INVALIDATION_STRATEGY "${name}", expected "full", "tags" or "ttl".`);
  }

  return strategy;
}
