<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Qa\Checklist;
use App\Domain\Qa\Jobs\RunQaChecks;
use App\Domain\Qa\Models\QaResult;
use App\Domain\Qa\TestRunner;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class QaChecklistTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_admins_see_the_checklist_only_where_it_is_enabled()
    {
        $this->actingAs($this->admin)->get(route('admin.qa-checklist.index'))
            ->assertInertia(fn ($page) => $page->component('admin/qa-checklist')
                ->where('sections.0.items.0.key', 'access.login')
                ->where('sections.0.items.0.address', 'http://'.config('app.domains.app').'/login')
                ->where('logins.admin.email', 'admin@paygate.local')
                ->where('qaChecklist', true));

        $branch = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();
        $this->actingAs($branch)->get(route('admin.qa-checklist.index'))->assertForbidden();

        config(['paygate.qa.enabled' => false]);
        $this->actingAs($this->admin)->get(route('admin.qa-checklist.index'))->assertNotFound();
    }

    public function test_every_item_is_unique_and_names_existing_tests()
    {
        $classes = [];

        foreach (glob(base_path('tests/{Feature,Unit}/{,*/}*Test.php'), GLOB_BRACE) ?: [] as $file) {
            $classes[basename($file, '.php')] = (string) file_get_contents($file);
        }

        $keys = [];

        foreach (Checklist::sections() as $section) {
            foreach ($section['items'] as $item) {
                $this->assertNotContains($item['key'], $keys, "Duplicate key {$item['key']}");
                $keys[] = $item['key'];

                foreach ($item['login'] as $login) {
                    $this->assertArrayHasKey($login, Checklist::LOGINS, "{$item['key']}: unknown login {$login}");
                }

                foreach ($item['tests'] as $test) {
                    [$class, $method] = array_pad(explode('::', $test, 2), 2, null);
                    $this->assertArrayHasKey($class, $classes, "{$item['key']}: no test class {$class}");

                    if ($method !== null) {
                        $this->assertStringContainsString("function {$method}(", $classes[$class], "{$item['key']}: no test {$test}");
                    }
                }
            }
        }
    }

    public function test_testers_mark_pass_or_fail_with_a_note()
    {
        $this->actingAs($this->admin)->put(route('admin.qa-checklist.mark', 'partners.wizard'), ['status' => 'fail', 'note' => 'Step 3 error text is cut off'])->assertSessionHasNoErrors();

        $result = QaResult::findOrFail('partners.wizard');
        $this->assertSame('fail', $result->manual_status);
        $this->assertSame($this->admin->id, $result->tested_by);
        $this->assertNotNull($result->tested_at);

        $this->actingAs($this->admin)->put(route('admin.qa-checklist.mark', 'partners.wizard'), ['status' => null])->assertSessionHasNoErrors();
        $this->assertNull($result->refresh()->manual_status);
        $this->assertNull($result->manual_note);

        $this->actingAs($this->admin)->put(route('admin.qa-checklist.mark', 'no.such.item'), ['status' => 'pass'])->assertSessionHasErrors('key');
    }

    public function test_re_run_queues_the_items_tests_and_stores_what_they_found()
    {
        $this->actingAs($this->admin)->post(route('admin.qa-checklist.run'), ['key' => 'finance.commissions'])->assertSessionHasNoErrors();
        Queue::assertPushedOn('qa', RunQaChecks::class, fn (RunQaChecks $job) => $job->keys === ['finance.commissions'] && ! $job->wholeSuite);
        $this->assertSame('queued', QaResult::findOrFail('finance.commissions')->auto_status);

        // Already queued: not twice.
        $this->actingAs($this->admin)->post(route('admin.qa-checklist.run'), ['key' => 'finance.commissions'])->assertSessionHasErrors('run');

        // The worker runs it (a fake runner stands in for PHPUnit here).
        $runner = new class extends TestRunner
        {
            /** @var list<string>|null */
            public ?array $asked = [];

            public function run(?array $patterns): array
            {
                $this->asked = $patterns;

                return ['output' => 'FAILURES!', 'tests' => [
                    'SettlementTest::test_the_commissions_page_adds_up_the_snapshotted_commissions' => ['status' => 'failed', 'message' => 'Failed asserting that 5 is 6.'],
                    'SettlementTest::test_something_else' => ['status' => 'passed', 'message' => null],
                ]];
            }
        };
        (new RunQaChecks(['finance.commissions']))->handle($runner);

        $this->assertSame(['SettlementTest::test_the_commissions_page_adds_up_the_snapshotted_commissions'], $runner->asked);
        $result = QaResult::findOrFail('finance.commissions');
        $this->assertSame('failed', $result->auto_status);
        $this->assertSame('1 of 1 failed', $result->auto_summary);
        $this->assertSame('Failed asserting that 5 is 6.', $result->auto_tests[0]['message'] ?? null);

        // "Run all" queues every item with tests and runs the whole suite once.
        $this->actingAs($this->admin)->post(route('admin.qa-checklist.run'))->assertSessionHasNoErrors();
        Queue::assertPushed(RunQaChecks::class, fn (RunQaChecks $job) => $job->wholeSuite && count($job->keys) === count(Checklist::items()));
    }

    public function test_junit_reports_are_read_per_test()
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites><testsuite name="all"><testsuite name="Tests\Unit\PartnerRulesTest">
  <testcase name="test_partner_lifecycle" class="Tests\Unit\PartnerRulesTest" assertions="3" time="0.01"/>
  <testcase name="test_bad_addresses_are_refused with data set &quot;not an ip&quot;" class="Tests\Unit\PartnerRulesTest" assertions="1" time="0.01"><failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that false is true.</failure></testcase>
</testsuite></testsuite></testsuites>
XML;
        $tests = (new TestRunner)->parse($xml);

        $this->assertSame('passed', $tests['PartnerRulesTest::test_partner_lifecycle']['status']);
        $this->assertSame('failed', $tests['PartnerRulesTest::test_bad_addresses_are_refused with data set "not an ip"']['status']);
        $this->assertTrue(TestRunner::covers(['PartnerRulesTest'], 'PartnerRulesTest::test_partner_lifecycle'));
        $this->assertTrue(TestRunner::covers(['PartnerRulesTest::test_bad_addresses_are_refused'], 'PartnerRulesTest::test_bad_addresses_are_refused with data set "x"'));
        $this->assertFalse(TestRunner::covers(['PartnerRules'], 'PartnerRulesTest::test_partner_lifecycle'));
    }

    public function test_test_pay_ins_come_with_their_payment_link()
    {
        $this->buildNetwork();
        $this->partner->update(['status' => 'active']);

        $this->actingAs($this->admin)->post(route('admin.qa-checklist.sample'), ['kind' => 'payin', 'partner_id' => $this->partner->id, 'amount' => '750'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('qa_created', fn ($created) => $created['kind'] === 'payin' && str_starts_with($created['url'], 'http://'.config('app.domains.pay').'/p/'));

        $this->assertSame(75000, Transaction::where('direction', 'payin')->sole()->amount);

        // No balance yet: the payout is refused with the API's reason.
        $this->actingAs($this->admin)->post(route('admin.qa-checklist.sample'), ['kind' => 'payout', 'partner_id' => $this->partner->id, 'amount' => '100'])
            ->assertSessionHasErrors('amount');
    }
}
