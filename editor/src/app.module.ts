import { Module } from '@nestjs/common';
import { ArticleEditor } from './articles/article-editor.service';
import { ArticleRepository } from './articles/article.repository';
import { ArticlesController } from './articles/articles.controller';
import { invalidatorProvider } from './cache/invalidator.provider';
import { FullPurgeInvalidator } from './cache/full-purge.invalidator';
import { NoopInvalidator } from './cache/noop.invalidator';
import { TagInvalidator } from './cache/tag.invalidator';
import { VarnishClient } from './cache/varnish.client';
import { Database } from './database/database';

@Module({
  controllers: [ArticlesController],
  providers: [
    Database,
    ArticleRepository,
    ArticleEditor,
    VarnishClient,
    FullPurgeInvalidator,
    TagInvalidator,
    NoopInvalidator,
    invalidatorProvider,
  ],
})
export class AppModule {}
