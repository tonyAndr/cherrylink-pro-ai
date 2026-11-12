<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use CherryLink\Editor\SuggestionsResults as S;
use PHPUnit\Framework\TestCase;

/** "All posts" typed search: word matches first, meaning-based hits only near the best. */
final class TypedSearchTest extends TestCase
{
    public function testNearBestDropsTheNoiseTail(): void
    {
        // Measured on the fixture for "SSL": the right post, then unrelated posts.
        $r = [['post_id' => 1, 'score' => 0.866], ['post_id' => 2, 'score' => 0.817], ['post_id' => 3, 'score' => 0.811]];
        $this->assertSame([1], \array_column(S::nearBest($r), 'post_id'));
        $this->assertSame([], S::nearBest([]));
    }

    public function testStemsTrimEndingsSoWordFormsMatch(): void
    {
        $this->assertSame(['наращива'], S::stems('наращивать'));      // also matches "наращивание"
        $this->assertSame(['ssl', 'плаг'], S::stems('SSL, плагин'));   // 5 letters -> 4, short words kept
        $this->assertSame([], S::stems(' – '));
    }

    public function testTitleMatchesThenMeaningThenTextOnly(): void
    {
        $matches = [7 => 'text', 5 => 'title', 9 => 'title', 8 => 'text'];  // SQL order
        $scores  = [3 => 0.86, 9 => 0.84, 7 => 0.85];                       // API order
        // titles by score (9, then unscored 5); API hits in API order (3, 7); text-only 8 last
        $this->assertSame([9, 5, 3, 7, 8], S::mergeWordMatches($matches, $scores));
    }
}
