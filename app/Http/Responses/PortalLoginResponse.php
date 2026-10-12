<?php

namespace App\Http\Responses;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class PortalLoginResponse implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return $request->routeIs('two-factor.login*')
                ? new JsonResponse('', 204)
                : response()->json(['two_factor' => false]);
        }

        $user = $request->user();
        $intended = $request->session()->get('url.intended');

        if ($user instanceof User && is_string($intended) && ! self::belongsTo($user->type, $intended)) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }

    private static function belongsTo(UserType $type, string $url): bool
    {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach (UserType::cases() as $portal) {
            $prefix = '/'.$portal->value;

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $portal === $type;
            }
        }

        return true;
    }
}
