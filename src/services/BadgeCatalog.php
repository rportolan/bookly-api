<?php
namespace App\Services;

final class BadgeCatalog
{
    /**
     * Chaque badge a:
     * - key: identifiant unique stocké dans user_badges.badge_key
     * - cat: pour les tabs
     * - icon/title/desc: UI
     * - metric: clé de stats utilisée pour value
     * - target: seuil
     */
    public static function all(): array
    {
        return [
            /* ---------------- Reading (Pages) ---------------- */

            ['key' => 'PAGES_30',    'cat' => 'Reading', 'icon' => '📄', 'title' => 'First pages',        'desc' => 'Lire 30 pages',         'metric' => 'pagesRead', 'target' => 30],
            ['key' => 'PAGES_100',   'cat' => 'Reading', 'icon' => '📘', 'title' => '100 pages',          'desc' => 'Lire 100 pages',        'metric' => 'pagesRead', 'target' => 100],
            ['key' => 'PAGES_300',   'cat' => 'Reading', 'icon' => '🔥', 'title' => 'Page grinder',       'desc' => 'Lire 300 pages',        'metric' => 'pagesRead', 'target' => 300],

            // New tiers
            ['key' => 'PAGES_500',   'cat' => 'Reading', 'icon' => '📗', 'title' => '500 pages',          'desc' => 'Lire 500 pages',        'metric' => 'pagesRead', 'target' => 500],
            ['key' => 'PAGES_750',   'cat' => 'Reading', 'icon' => '🚀', 'title' => 'Momentum',           'desc' => 'Lire 750 pages',        'metric' => 'pagesRead', 'target' => 750],
            ['key' => 'PAGES_1000',  'cat' => 'Reading', 'icon' => '📚', 'title' => '1K club',            'desc' => 'Lire 1000 pages',       'metric' => 'pagesRead', 'target' => 1000],
            ['key' => 'PAGES_1500',  'cat' => 'Reading', 'icon' => '⛰️', 'title' => 'Mountain',           'desc' => 'Lire 1500 pages',       'metric' => 'pagesRead', 'target' => 1500],
            ['key' => 'PAGES_2500',  'cat' => 'Reading', 'icon' => '🔥', 'title' => 'Page machine',       'desc' => 'Lire 2500 pages',       'metric' => 'pagesRead', 'target' => 2500],
            ['key' => 'PAGES_5000',  'cat' => 'Reading', 'icon' => '🏔️', 'title' => 'Mountain of pages',  'desc' => 'Lire 5000 pages',       'metric' => 'pagesRead', 'target' => 5000],
            ['key' => 'PAGES_10000', 'cat' => 'Reading', 'icon' => '🌌', 'title' => 'Cosmic reader',       'desc' => 'Lire 10000 pages',      'metric' => 'pagesRead', 'target' => 10000],

            /* ---------------- Books (Completed) ---------------- */

            ['key' => 'DONE_1',   'cat' => 'Books', 'icon' => '✅', 'title' => 'Book finisher',     'desc' => 'Terminer 1 livre',   'metric' => 'done', 'target' => 1],
            ['key' => 'DONE_3',   'cat' => 'Books', 'icon' => '🏅', 'title' => 'Trilogie',          'desc' => 'Terminer 3 livres',  'metric' => 'done', 'target' => 3],
            ['key' => 'DONE_5',   'cat' => 'Books', 'icon' => '🏆', 'title' => 'Collector',         'desc' => 'Terminer 5 livres',  'metric' => 'done', 'target' => 5],

            // New tiers
            ['key' => 'DONE_10',  'cat' => 'Books', 'icon' => '🥇', 'title' => 'Décathlon',          'desc' => 'Terminer 10 livres', 'metric' => 'done', 'target' => 10],
            ['key' => 'DONE_15',  'cat' => 'Books', 'icon' => '📚', 'title' => 'Bookshelf',          'desc' => 'Terminer 15 livres', 'metric' => 'done', 'target' => 15],
            ['key' => 'DONE_25',  'cat' => 'Books', 'icon' => '👑', 'title' => 'Library owner',      'desc' => 'Terminer 25 livres', 'metric' => 'done', 'target' => 25],
            ['key' => 'DONE_50',  'cat' => 'Books', 'icon' => '🏛️', 'title' => 'Grand lecteur',      'desc' => 'Terminer 50 livres', 'metric' => 'done', 'target' => 50],
            ['key' => 'DONE_100', 'cat' => 'Books', 'icon' => '🌟', 'title' => 'Living legend',      'desc' => 'Terminer 100 livres','metric' => 'done', 'target' => 100],

            /* ---------------- Notes (Vocab + Quotes) ---------------- */

            // Vocab
            ['key' => 'VOCAB_5',    'cat' => 'Notes', 'icon' => '🧠', 'title' => 'Word hunter',       'desc' => 'Ajouter 5 mots',      'metric' => 'vocab',  'target' => 5],
            ['key' => 'VOCAB_20',   'cat' => 'Notes', 'icon' => '📚', 'title' => 'Lexicon',           'desc' => 'Ajouter 20 mots',     'metric' => 'vocab',  'target' => 20],

            // New tiers
            ['key' => 'VOCAB_50',   'cat' => 'Notes', 'icon' => '🧩', 'title' => 'Word collector',    'desc' => 'Ajouter 50 mots',     'metric' => 'vocab',  'target' => 50],
            ['key' => 'VOCAB_100',  'cat' => 'Notes', 'icon' => '📘', 'title' => 'Dictionary mode',   'desc' => 'Ajouter 100 mots',    'metric' => 'vocab',  'target' => 100],
            ['key' => 'VOCAB_200',  'cat' => 'Notes', 'icon' => '🧠', 'title' => 'Lexicon XXL',       'desc' => 'Ajouter 200 mots',    'metric' => 'vocab',  'target' => 200],
            ['key' => 'VOCAB_500',  'cat' => 'Notes', 'icon' => '🗝️', 'title' => 'Word master',       'desc' => 'Ajouter 500 mots',    'metric' => 'vocab',  'target' => 500],

            // Quotes
            ['key' => 'QUOTES_3',   'cat' => 'Notes', 'icon' => '💬', 'title' => 'Quoter',            'desc' => 'Ajouter 3 citations',   'metric' => 'quotes', 'target' => 3],
            ['key' => 'QUOTES_10',  'cat' => 'Notes', 'icon' => '🗣️', 'title' => 'Citation master',   'desc' => 'Ajouter 10 citations',  'metric' => 'quotes', 'target' => 10],

            // New tiers
            ['key' => 'QUOTES_25',  'cat' => 'Notes', 'icon' => '📜', 'title' => 'Collector',         'desc' => 'Ajouter 25 citations',  'metric' => 'quotes', 'target' => 25],
            ['key' => 'QUOTES_50',  'cat' => 'Notes', 'icon' => '🏺', 'title' => 'Anthology',         'desc' => 'Ajouter 50 citations',  'metric' => 'quotes', 'target' => 50],
            ['key' => 'QUOTES_100', 'cat' => 'Notes', 'icon' => '🏛️', 'title' => 'Curator',           'desc' => 'Ajouter 100 citations', 'metric' => 'quotes', 'target' => 100],

            /* ---------------- First-time / Instant unlock badges ----------------
             * Ces badges ne dépendent pas d'une métrique "stats" (metric = null)
             * Ils sont déverrouillés par ProgressService::checkAndUnlockBadgesMvp()
             */

            ['key' => 'FIRST_BOOK',          'cat' => 'Milestones', 'icon' => '✨', 'title' => 'First book',          'desc' => 'Créer un livre',            'metric' => null, 'target' => 0],
            ['key' => 'FIRST_QUOTE',         'cat' => 'Milestones', 'icon' => '✨', 'title' => 'First quote',         'desc' => 'Ajouter une citation',      'metric' => null, 'target' => 0],
            ['key' => 'FIRST_VOCAB',         'cat' => 'Milestones', 'icon' => '✨', 'title' => 'First vocab',         'desc' => 'Ajouter un mot',            'metric' => null, 'target' => 0]
        ];
    }
}
