<?php

namespace Tests\Feature;

use App\Support\HelpCenter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HelpArticleMetadataTest extends TestCase
{
    public function test_every_article_is_tagged_with_its_area_and_a_modified_date(): void
    {
        $article = app(HelpCenter::class)->find('billing', 'late-fees');

        $this->assertSame(['area' => 'billing'], $article->tags);
        $this->assertNotNull($article->modifiedAt);
    }

    public function test_front_matter_plans_and_audience_become_lists(): void
    {
        $directory = sys_get_temp_dir().'/help-'.uniqid();
        File::makeDirectory($directory.'/billing', recursive: true);
        File::put($directory.'/billing/terms.md', "---\ntitle: Terms\ncategory: billing\nplans: pro, business\naudience: owners\nproduct: invoicing\n---\n\n# Terms\n\nText.\n");

        $article = (new HelpCenter($directory))->find('billing', 'terms');

        File::deleteDirectory($directory);
        $this->assertSame([
            'area' => 'billing',
            'plans' => ['pro', 'business'],
            'audience' => ['owners'],
            'product' => 'invoicing',
        ], $article->tags);
    }

    public function test_article_pages_give_every_heading_an_anchor_the_citation_links_use(): void
    {
        $this->get('help/billing/late-fees')
            ->assertOk()
            ->assertSee('id="how-the-fee-is-calculated"', false);
    }
}
