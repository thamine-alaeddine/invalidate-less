<?php

namespace App\Content;

use Random\Randomizer;

// Builds fake but deterministic names and texts: same Randomizer state, same output.
final class TextGenerator
{
    private const FIRST_NAMES = ['Alice', 'Bruno', 'Chloé', 'David', 'Emma', 'Farid', 'Giulia', 'Hugo', 'Inès', 'Jonas', 'Karim', 'Léa', 'Malik', 'Nina', 'Omar', 'Paula', 'Quentin', 'Rosa', 'Sami', 'Tess'];
    private const LAST_NAMES = ['Martin', 'Bernard', 'Dubois', 'Garcia', 'Haddad', 'Leroy', 'Moreau', 'Nguyen', 'Petit', 'Rossi', 'Schmidt', 'Silva', 'Thomas', 'Weber', 'Yilmaz'];
    private const TOPICS = ['Politics', 'Economy', 'Science', 'Health', 'Sports', 'Culture', 'Technology', 'Climate', 'Education', 'Travel', 'Food', 'Music', 'Cinema', 'Books', 'Business', 'World', 'Cities', 'Space', 'Energy', 'Law'];
    private const WORDS = ['city', 'council', 'market', 'report', 'study', 'record', 'plan', 'river', 'vote', 'team', 'festival', 'budget', 'school', 'storm', 'launch', 'deal', 'court', 'harbour', 'museum', 'network', 'project', 'summit', 'trial', 'season', 'crisis', 'reform', 'startup', 'station', 'border', 'harvest'];
    private const VERBS = ['approves', 'rejects', 'reveals', 'delays', 'expands', 'questions', 'celebrates', 'faces', 'targets', 'announces'];

    public static function categoryName(int $index): string
    {
        return self::TOPICS[$index % \count(self::TOPICS)].($index >= \count(self::TOPICS) ? ' '.($index + 1) : '');
    }

    public static function personName(Randomizer $random): string
    {
        return self::pick($random, self::FIRST_NAMES).' '.self::pick($random, self::LAST_NAMES);
    }

    public static function title(Randomizer $random): string
    {
        return ucfirst(self::pick($random, self::WORDS)).' '.self::pick($random, self::VERBS).' '.self::pick($random, self::WORDS).' '.self::pick($random, self::WORDS);
    }

    public static function body(Randomizer $random): string
    {
        return implode("\n\n", array_map(static fn () => self::sentence($random), range(1, 4)));
    }

    public static function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }

    /**
     * @param list<string> $list
     */
    private static function pick(Randomizer $random, array $list): string
    {
        return $list[$random->getInt(0, \count($list) - 1)];
    }

    private static function sentence(Randomizer $random): string
    {
        $words = array_map(static fn () => self::pick($random, self::WORDS), range(1, $random->getInt(8, 16)));

        return ucfirst(implode(' ', $words)).'.';
    }
}
