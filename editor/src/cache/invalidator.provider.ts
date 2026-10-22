import { FactoryProvider } from '@nestjs/common';
import { ARTICLE_INVALIDATOR, ArticleInvalidator } from './article-invalidator';
import { FullPurgeInvalidator } from './full-purge.invalidator';
import { NoopInvalidator } from './noop.invalidator';
import { TagInvalidator } from './tag.invalidator';

// Picks the strategy named by INVALIDATION_STRATEGY; an unknown value stops the service at boot.
export const invalidatorProvider: FactoryProvider<ArticleInvalidator> = {
  provide: ARTICLE_INVALIDATOR,
  inject: [FullPurgeInvalidator, TagInvalidator, NoopInvalidator],
  useFactory: (full: FullPurgeInvalidator, tags: TagInvalidator, ttl: NoopInvalidator) => {
    const strategy = process.env.INVALIDATION_STRATEGY ?? 'full';
    const strategies: Record<string, ArticleInvalidator> = { full, tags, ttl };
    if (!(strategy in strategies)) {
      throw new Error(`Unknown INVALIDATION_STRATEGY "${strategy}", expected "full", "tags" or "ttl".`);
    }

    return strategies[strategy];
  },
};
