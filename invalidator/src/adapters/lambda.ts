import type { SQSBatchResponse, SQSEvent } from 'aws-lambda';
import { parseArticleEvent } from '../core/article-event';
import { handleEvent } from '../core/handle-event';
import { strategyFromEnv } from '../core/strategies';
import { VarnishClient } from '../varnish/varnish-client';

const strategy = strategyFromEnv();
const varnish = new VarnishClient();

// AWS Lambda SQS trigger; needs ReportBatchItemFailures on the event source mapping so only failed messages are retried.
export async function handler(event: SQSEvent): Promise<SQSBatchResponse> {
  const results = await Promise.allSettled(event.Records.map(async (record) => handleEvent(parseArticleEvent(record.body), strategy, varnish)));

  return {
    batchItemFailures: results.flatMap((result, i) => {
      if (result.status === 'fulfilled') return [];
      console.error(`message ${event.Records[i].messageId} failed:`, result.reason);
      return [{ itemIdentifier: event.Records[i].messageId }];
    }),
  };
}
