import type { SQSEvent } from 'aws-lambda';
import { handler } from '../src/adapters/lambda';

// Runs the Lambda adapter locally on a sample SQS event: node dist/scripts/invoke-lambda.js [articleId] [categoryId] [authorId] [type]
const [id = '1', categoryId = '1', authorId = '1', type = 'article.updated'] = process.argv.slice(2);
const body = JSON.stringify({
  specversion: '1.0',
  type,
  source: 'invoke-lambda',
  id: crypto.randomUUID(),
  time: new Date().toISOString(),
  data: { id: Number(id), categoryId: Number(categoryId), authorId: Number(authorId) },
});
const event = { Records: [{ messageId: 'local-1', body }] } as SQSEvent;

handler(event).then((response) => console.log(JSON.stringify(response)));
