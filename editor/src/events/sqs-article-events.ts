import { SendMessageCommand, SQSClient } from '@aws-sdk/client-sqs';
import { Injectable } from '@nestjs/common';
import { randomUUID } from 'node:crypto';
import { Article } from '../articles/article';
import { ArticleEvents } from './article-events';

// Sends CloudEvents to an SQS queue; SQS_ENDPOINT points at ElasticMQ locally, unset on AWS.
@Injectable()
export class SqsArticleEvents implements ArticleEvents {
  private readonly sqs = new SQSClient({ endpoint: process.env.SQS_ENDPOINT });
  private readonly queueUrl = process.env.QUEUE_URL ?? 'http://sqs:9324/000000000000/article-events';

  updated(article: Article): Promise<void> {
    return this.send('article.updated', article);
  }

  published(article: Article): Promise<void> {
    return this.send('article.published', article);
  }

  private async send(type: string, article: Article): Promise<void> {
    const event = {
      specversion: '1.0',
      type,
      source: 'editor',
      id: randomUUID(),
      time: new Date().toISOString(),
      data: { id: article.id, categoryId: article.categoryId, authorId: article.authorId },
    };
    await this.sqs.send(new SendMessageCommand({ QueueUrl: this.queueUrl, MessageBody: JSON.stringify(event) }));
  }
}
