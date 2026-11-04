<?php

namespace App\Http\Admin\Developer;

use App\Domain\Partner\PartnerIntegrationFile;
use App\Http\Controller;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Developer › Partner API Docs: the standard integration guide (the partner
 * file without any partner's details), shown on the page and downloaded as
 * PDF to share with a partner's developers. Admins see it once a super
 * admin opens it in Section rollout.
 */
class PartnerApiDocsController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('partners.view');

        return Inertia::render('admin/partner-api-docs');
    }

    public function preview(PartnerIntegrationFile $file): HttpResponse
    {
        Gate::authorize('partners.view');

        return response($file->standardHtml());
    }

    public function download(PartnerIntegrationFile $file): FileResponse
    {
        Gate::authorize('partners.view');

        return $file->downloadStandard();
    }
}
