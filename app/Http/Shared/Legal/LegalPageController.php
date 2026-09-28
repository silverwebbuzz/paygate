<?php

namespace App\Http\Shared\Legal;

use App\Domain\Platform\Models\Page;
use App\Http\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A published content page, public (portal and payment-page hosts).
 */
class LegalPageController extends Controller
{
    public function show(string $slug): Response
    {
        $page = Page::query()->where(['slug' => $slug, 'status' => 'published'])->firstOrFail();

        return Inertia::render('legal/show', [
            'title' => $page->title,
            'html' => $page->html(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]);
    }
}
