<?php

namespace App\Mcp\Resources;

use App\Support\HelpArticle;
use App\Support\HelpCenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('help_article')]
#[Description('One help center article as Markdown, addressed as help://articles/{category}/{slug}.')]
#[MimeType('text/markdown')]
final class HelpArticleResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('help://articles/{category}/{slug}');
    }

    public function handle(Request $request, HelpCenter $helpCenter): Response
    {
        $article = $helpCenter->all()->first(
            fn (HelpArticle $candidate): bool => $candidate->category === $request->get('category')
                && $candidate->slug === $request->get('slug'),
        );

        if (! $article) {
            return Response::error('Help article not found.');
        }

        return Response::text("# {$article->title}\n\n{$article->markdown}");
    }
}
