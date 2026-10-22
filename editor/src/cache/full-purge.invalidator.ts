import { Injectable } from '@nestjs/common';
import { ArticleInvalidator } from './article-invalidator';
import { VarnishClient } from './varnish.client';

// Strategy A: ban every cached page and fragment, whatever changed.
@Injectable()
export class FullPurgeInvalidator implements ArticleInvalidator {
  constructor(private readonly varnish: VarnishClient) {}

  articleUpdated(): Promise<void> {
    return this.varnish.banAll();
  }

  articlePublished(): Promise<void> {
    return this.varnish.banAll();
  }
}
