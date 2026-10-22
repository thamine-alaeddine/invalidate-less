// Fake but deterministic titles and bodies: same seed, same output (word lists from the site's TextGenerator.php).
const WORDS = ['city', 'council', 'market', 'report', 'study', 'record', 'plan', 'river', 'vote', 'team', 'festival', 'budget', 'school', 'storm', 'launch', 'deal', 'court', 'harbour', 'museum', 'network', 'project', 'summit', 'trial', 'season', 'crisis', 'reform', 'startup', 'station', 'border', 'harvest'];
const VERBS = ['approves', 'rejects', 'reveals', 'delays', 'expands', 'questions', 'celebrates', 'faces', 'targets', 'announces'];

export class TextGenerator {
  private state: number;

  constructor(seed: number) {
    this.state = seed >>> 0;
  }

  static slugify(text: string): string {
    return text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  title(): string {
    return `${capitalize(this.pick(WORDS))} ${this.pick(VERBS)} ${this.pick(WORDS)} ${this.pick(WORDS)}`;
  }

  body(): string {
    return Array.from({ length: 4 }, () => this.sentence()).join('\n\n');
  }

  private sentence(): string {
    const words = Array.from({ length: this.int(8, 16) }, () => this.pick(WORDS));

    return `${capitalize(words.join(' '))}.`;
  }

  private pick(list: string[]): string {
    return list[this.int(0, list.length - 1)];
  }

  private int(min: number, max: number): number {
    return min + Math.floor(this.next() * (max - min + 1));
  }

  // mulberry32
  private next(): number {
    this.state = (this.state + 0x6d2b79f5) >>> 0;
    let t = this.state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  }
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}
