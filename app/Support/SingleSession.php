<?php

namespace App\Support;

/**
 * One login per user at a time (client request 2026-11-27). Each sign-in
 * stores a new random token on the user (users.session_token) and under
 * this key in that browser's session; EnsureSingleSession ends any session
 * whose token no longer matches, i.e. one replaced by a newer sign-in.
 */
final class SingleSession
{
    public const SESSION_KEY = 'auth.session_token';
}
