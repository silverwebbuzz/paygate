<?php

namespace App\Http\Admin\Qa;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\SectionRollout;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section rollout (super admins only): the master switch and, per portal,
 * which menu items users other than super admins may see and open while a
 * client tests one section at a time.
 */
class SectionRolloutController extends Controller
{
    public function index(Request $request, SectionRollout $rollout): Response
    {
        $this->ensureSuperAdmin($request);

        $portals = [];

        foreach (SectionRollout::SECTIONS as $portal => $groups) {
            foreach ($groups as $group => $items) {
                $portals[$portal][] = [
                    'label' => $group,
                    'items' => array_map(fn (string $key, array $item) => ['key' => $key, 'label' => $item['label'], 'path' => $item['paths'][0]], array_keys($items), $items),
                ];
            }
        }

        return Inertia::render('admin/section-rollout', [
            'portals' => $portals,
            'state' => $rollout->state(),
        ]);
    }

    public function update(Request $request, SectionRollout $rollout): RedirectResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'open' => ['present', 'array'],
            'open.*' => ['array'],
            'open.*.*' => ['string', 'max:40'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var array<string, list<string>> $open */
        $open = $data['open'];
        $rollout->save((bool) $data['active'], $open, $actor);

        return back();
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin() === true, 403);
    }
}
