import { Module } from '@nestjs/common';
import { ArticleEditor } from './articles/article-editor.service';
import { ArticleRepository } from './articles/article.repository';
import { ArticlesController } from './articles/articles.controller';
import { Database } from './database/database';
import { ARTICLE_EVENTS } from './events/article-events';
import { SqsArticleEvents } from './events/sqs-article-events';

@Module({
  controllers: [ArticlesController],
  providers: [
    Database,
    ArticleRepository,
    ArticleEditor,
    { provide: ARTICLE_EVENTS, useClass: SqsArticleEvents },
  ],
})
export class AppModule {}
