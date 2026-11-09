<?php

namespace Tests\Feature;

use App\Support\HelpArticle;
use App\Support\HelpCenter;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    public function test_help_center_has_one_hundred_twenty_articles(): void
    {
        $articles = app(HelpCenter::class)->all();

        $this->assertCount(120, $articles);
    }

    public function test_every_article_has_front_matter_title_matching_its_heading(): void
    {
        $articles = app(HelpCenter::class)->all();

        $articles->each(function (HelpArticle $article) {
            $this->assertStringStartsWith("# {$article->title}", $article->markdown, $article->path());
        });
    }

    public function test_late_fees_article_describes_the_policy(): void
    {
        $article = app(HelpCenter::class)->find('billing', 'late-fees');

        $this->assertSame('Understanding late fees', $article->title);
        $this->assertStringContainsString('1.5%', $article->markdown);
        $this->assertStringContainsString('automatically 30 days after', $article->markdown);
    }

    public function test_help_index_and_article_pages_render(): void
    {
        $this->get('help')->assertOk()->assertSee('Understanding late fees');

        $this->get('help/billing/late-fees')->assertOk()->assertSee('How the fee is calculated');
    }

    public function test_unknown_article_returns_not_found(): void
    {
        $this->get('help/billing/no-such-article')->assertNotFound();
    }
}
