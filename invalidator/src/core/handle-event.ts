import { ArticleEvent } from './article-event';
import { Strategy } from './strategies';

// Port to the HTTP cache; Varnish today, any purge-capable cache tomorrow.
export interface Cache {
  banAll(): Promise<void>;
  purgeTags(tags: string[]): Promise<void>;
}

// Shared by every entry adapter (worker, Lambda); rejects when the purge fails so the message is retried.
export async function handleEvent(event: ArticleEvent, strategy: Strategy, cache: Cache): Promise<void> {
  const purge = strategy(event);
  if (purge.kind === 'ban-all') {
    await cache.banAll();
  } else if (purge.kind === 'tags') {
    await cache.purgeTags(purge.tags);
  }
}
