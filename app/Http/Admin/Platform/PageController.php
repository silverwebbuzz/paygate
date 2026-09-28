<?php

namespace App\Http\Admin\Platform;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Actions\ManagePlatformContent;
use App\Domain\Platform\Models\Page;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Content pages (Admin › Global Settings › Pages): terms, privacy, payment
 * help… in Markdown; published ones are public at /legal/{slug}.
 */
class PageController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('settings.view');

        return Inertia::render('admin/pages', [
            'pages' => Page::query()->orderBy('title')->get()->map(fn (Page $page) => [
                ...$page->only(['id', 'slug', 'title', 'body', 'status']),
                'updated_at' => $page->updated_at?->toIso8601String(),
                'url' => route('legal.show', $page->slug),
            ]),
        ]);
    }

    public function store(Request $request, ManagePlatformContent $content): RedirectResponse
    {
        Gate::authorize('settings.update');

        $page = $content->savePage($this->actor($request), null, $this->validated($request, true));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page “:title” created.', ['title' => $page->title])]);

        return back();
    }

    public function update(Request $request, Page $page, ManagePlatformContent $content): RedirectResponse
    {
        Gate::authorize('settings.update');

        $content->savePage($this->actor($request), $page, $this->validated($request, false));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page “:title” saved.', ['title' => $page->title])]);

        return back();
    }

    /**
     * @return array{title: string, body: string, status: string, slug?: string|null}
     */
    private function validated(Request $request, bool $new): array
    {
        /** @var array{title: string, body: string, status: string, slug?: string|null} */
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => $new ? ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]*$/'] : ['prohibited'],
            'body' => ['required', 'string', 'max:50000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
