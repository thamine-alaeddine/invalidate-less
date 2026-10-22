import { Injectable } from '@nestjs/common';
import { ArticleInvalidator } from './article-invalidator';

// TTL only: purge nothing, content refreshes when the short TTL expires.
@Injectable()
export class NoopInvalidator implements ArticleInvalidator {
  async articleUpdated(): Promise<void> {}

  async articlePublished(): Promise<void> {}
}
