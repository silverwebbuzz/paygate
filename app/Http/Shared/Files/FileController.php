<?php

namespace App\Http\Shared\Files;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Http\Shared\Reconciliation\ReconciliationScope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a private file after checking the viewer may see what it belongs
 * to. Payment proofs: Admin and the receiving branch only; statement files:
 * Admin and the branch (downloaded, never shown inline). Never cached.
 */
class FileController extends Controller
{
    public function show(Request $request, StoredFile $file): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $owner = $file->attachable;

        $allowed = match (true) {
            $owner instanceof Transaction => $file->purpose === 'payment_proof'
                && $viewer->type !== UserType::Partner
                && $viewer->can('view', $owner),
            // Imported bank statements: the branch's statement users and Admin.
            $owner instanceof StatementImport => $file->purpose === 'statement'
                && $viewer->can('statements.view')
                && ReconciliationScope::allows($viewer, $owner->branch_id),
            default => false,
        };

        abort_unless($allowed, 404);

        $contents = $file->contents();
        abort_if($contents === null, 404);

        AuditLog::record('file.viewed', $owner, [], ['file_id' => $file->id, 'purpose' => $file->purpose], $viewer);

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->original_name ?? 'file') ?? 'file';

        return response($contents, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => ($file->purpose === 'payment_proof' ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }
}
