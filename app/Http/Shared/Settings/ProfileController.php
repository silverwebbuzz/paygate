<?php

namespace App\Http\Shared\Settings;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Http\Controller;
use App\Http\Shared\Settings\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/profile');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $old = $user->only('name');

        $user->fill($request->validated());

        if ($user->isDirty()) {
            $user->save();
            AuditLog::record('user.profile_updated', $user, $old, $user->only('name'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
