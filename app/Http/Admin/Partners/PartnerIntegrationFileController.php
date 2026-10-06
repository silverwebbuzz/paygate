<?php

namespace App\Http\Admin\Partners;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\PartnerIntegrationFile;
use App\Http\Controller;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PartnerIntegrationFileController extends Controller
{
    public function download(Partner $partner, PartnerIntegrationFile $file): Response
    {
        Gate::authorize('partners.view');
        abort_unless(PartnerIntegrationFile::ready($partner), 404);

        return $file->download($partner, null);
    }

    public function issued(Partner $partner, PartnerIntegrationFile $file): Response
    {
        Gate::authorize('partners.view');

        return $file->download($partner, PartnerIntegrationFile::takeSecret($partner));
    }
}
