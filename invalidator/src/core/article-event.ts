// CloudEvents 1.0 envelope, so any broker (SQS, RabbitMQ, Pub/Sub…) can carry it unchanged.
export interface ArticleEvent {
  specversion: '1.0';
  type: 'article.updated' | 'article.published';
  source: string;
  id: string;
  time: string;
  data: { id: number; categoryId: number; authorId: number };
}

const TYPES = ['article.updated', 'article.published'];

export function parseArticleEvent(body: string): ArticleEvent {
  const event = JSON.parse(body) as ArticleEvent;
  const data = event?.data;
  if (event?.specversion !== '1.0' || !TYPES.includes(event.type) || ![data?.id, data?.categoryId, data?.authorId].every(Number.isInteger)) {
    throw new Error(`Not an article event: ${body}`);
  }

  return event;
}
