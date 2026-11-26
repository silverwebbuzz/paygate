<?php

namespace App\Http\Branch\Guide;

use App\Http\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guide (Branch portal, client request 2026-11-26): how to use the branch
 * portal step by step, with screenshots, for every branch user (admin and
 * operator). The content lives in resources/js/lib/branch-guide-content.ts
 * and the pictures in public/guide/branch/.
 *
 * Not listed in SectionRollout on purpose, so it is never hidden.
 */
class GuideController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('branch/guide');
    }
}
