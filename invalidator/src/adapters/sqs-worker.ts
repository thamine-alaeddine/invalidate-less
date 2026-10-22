import { DeleteMessageCommand, Message, ReceiveMessageCommand, SQSClient } from '@aws-sdk/client-sqs';
import { parseArticleEvent } from '../core/article-event';
import { handleEvent } from '../core/handle-event';
import { strategyFromEnv } from '../core/strategies';
import { VarnishClient } from '../varnish/varnish-client';

// Long-running consumer for any container platform; SQS_ENDPOINT points at ElasticMQ locally, unset on AWS.
const queueUrl = process.env.QUEUE_URL ?? 'http://sqs:9324/000000000000/article-events';
const sqs = new SQSClient({ endpoint: process.env.SQS_ENDPOINT });
const strategy = strategyFromEnv();
const varnish = new VarnishClient();
let running = true;

// A failed message is not deleted: SQS redelivers it after the visibility timeout, then moves it to the DLQ.
async function handle(message: Message): Promise<void> {
  try {
    const event = parseArticleEvent(message.Body ?? '');
    await handleEvent(event, strategy, varnish);
    await sqs.send(new DeleteMessageCommand({ QueueUrl: queueUrl, ReceiptHandle: message.ReceiptHandle }));
    console.log(`${event.type} ${event.data.id} handled`);
  } catch (error) {
    console.error(`message ${message.MessageId} failed:`, error);
  }
}

async function main(): Promise<void> {
  console.log(`invalidator worker on ${queueUrl}, strategy ${process.env.INVALIDATION_STRATEGY ?? 'full'}`);
  while (running) {
    try {
      const { Messages = [] } = await sqs.send(new ReceiveMessageCommand({ QueueUrl: queueUrl, MaxNumberOfMessages: 10, WaitTimeSeconds: 20 }));
      await Promise.all(Messages.map(handle));
    } catch (error) {
      console.error('receive failed:', error);
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
  }
}

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => {
    running = false;
    process.exit(0);
  });
}

void main();
