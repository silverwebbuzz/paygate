<?php

namespace Tests\Feature\Database;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_users_get_a_unique_username_from_their_email()
    {
        $ravi = User::factory()->create(['email' => 'Ravi.K@example.com']);
        $raviElsewhere = User::factory()->create(['email' => 'ravi.k@other.example']);
        $short = User::factory()->create(['email' => 'a@example.com']);
        $odd = User::factory()->create(['email' => 'jo+ops@example.com']);

        $migration = require database_path('migrations/2026_10_05_100002_add_username_to_users.php');
        $migration->down();
        $migration->up();

        $usernames = [
            $ravi->refresh()->username,
            $raviElsewhere->refresh()->username,
            $short->refresh()->username,
            $odd->refresh()->username,
        ];

        $this->assertEqualsCanonicalizing(['ravi.k', 'ravi.k2'], array_slice($usernames, 0, 2));
        $this->assertSame('a00', $usernames[2]);
        $this->assertSame('joops', $usernames[3]);
        $this->assertSame(User::count(), User::query()->distinct()->count('username'));
    }
}
