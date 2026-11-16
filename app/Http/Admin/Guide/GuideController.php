<?php

namespace App\Http\Admin\Guide;

use App\Http\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guide (Admin only, client request 2026-11-16): one page that explains the
 * whole system in simple English, menu by menu and flow by flow, so an admin
 * can answer a branch's or partner's question. The content lives in the page
 * (resources/js/pages/admin/guide.tsx); every admin may open it.
 *
 * Not listed in SectionRollout on purpose, so it is never hidden.
 */
class GuideController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/guide');
    }
}
