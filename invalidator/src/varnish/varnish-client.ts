import { Cache } from '../core/handle-event';

// Sends BAN and PURGEKEYS requests the way FOSHttpCacheBundle did; resolves once Varnish has applied them.
export class VarnishClient implements Cache {
  constructor(private readonly url = process.env.VARNISH_URL ?? 'http://varnish') {}

  // Matches the VCL's lurker-friendly ban on X-Host, X-Url and Content-Type.
  async banAll(): Promise<void> {
    await this.send('BAN', { 'X-Host': '.*', 'X-Url': '.*', 'X-Content-Type': '.*' });
  }

  async purgeTags(tags: string[]): Promise<void> {
    await this.send('PURGEKEYS', { 'xkey-purge': tags.join(' ') });
  }

  private async send(method: string, headers: Record<string, string>): Promise<void> {
    const response = await fetch(`${this.url}/`, { method, headers, signal: AbortSignal.timeout(5000) });
    if (!response.ok) {
      throw new Error(`Varnish ${method} failed: ${response.status} ${await response.text()}`);
    }
  }
}
