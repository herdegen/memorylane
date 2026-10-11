<?php

namespace Tests\Feature;

use App\Console\Commands\HealthCheckCommand;
use App\Filament\Widgets\SystemHealth;
use App\Models\User;
use App\Notifications\SystemHealthAlert;
use App\Services\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Livewire\Livewire;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/health-'.Str::random(8));
        File::ensureDirectoryExists($this->dir.'/dumps');
        File::ensureDirectoryExists($this->dir.'/logs');
        $this->app->instance(HealthCheck::class, new HealthCheck($this->dir.'/backup-media.log', $this->dir.'/dumps', $this->dir.'/logs'));
        $this->horizon('running');

        $this->admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'user']);

        $this->backupLog(['-2 hours' => 'copie : TERMINÉE OK']);
        $dump = $this->dir.'/dumps/memorylane-test.dump';
        file_put_contents($dump, str_repeat('x', 200 * 1024));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function horizon(?string $status): void
    {
        $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')
            ->andReturn($status ? [(object) ['status' => $status]] : []));
    }

    /** @param array<string, string> $events décalage relatif => message */
    private function backupLog(array $events): void
    {
        $lines = [];
        foreach ($events as $when => $message) {
            $lines[] = now('UTC')->modify($when)->format('Y-m-d H:i:s').' '.$message;
        }
        file_put_contents($this->dir.'/backup-media.log', implode("\n", $lines)."\n");
    }

    private function problemKeys(): array
    {
        return collect(Cache::get(HealthCheckCommand::RESULT_KEY)['checks'])->where('ok', false)->pluck('key')->all();
    }

    public function test_all_green_sends_nothing_and_stores_the_result(): void
    {
        Notification::fake();

        $this->artisan('memorylane:health-check')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([], $this->problemKeys());
    }

    public function test_missed_media_backup_alerts_admins_once(): void
    {
        Notification::fake();
        $this->backupLog(['-30 hours' => 'copie : TERMINÉE OK']);

        $this->artisan('memorylane:health-check')->assertFailed();
        $this->artisan('memorylane:health-check')->assertFailed();

        $this->assertSame(['backup_media'], $this->problemKeys());
        Notification::assertSentToTimes($this->admin, SystemHealthAlert::class, 1);
        Notification::assertSentTo($this->admin, SystemHealthAlert::class,
            fn ($n) => $n->problems[0]['label'] === 'Sauvegarde des photos (OVH)');
        Notification::assertCount(1);
    }

    public function test_failed_copy_after_a_success_is_a_problem(): void
    {
        $this->backupLog(['-3 hours' => 'copie : TERMINÉE OK', '-1 hour' => 'copie : ÉCHEC (code 1)']);

        $this->artisan('memorylane:health-check')->assertFailed();

        $this->assertSame(['backup_media'], $this->problemKeys());
    }

    public function test_recovery_sends_an_all_clear(): void
    {
        Notification::fake();
        $this->backupLog(['-30 hours' => 'copie : TERMINÉE OK']);
        $this->artisan('memorylane:health-check');

        $this->backupLog(['-1 hour' => 'copie : TERMINÉE OK']);
        $this->artisan('memorylane:health-check')->assertSuccessful();

        Notification::assertSentTo($this->admin, SystemHealthAlert::class, fn ($n) => $n->problems === []);
        $this->assertNull(Cache::get(HealthCheckCommand::ALERT_KEY));
    }

    public function test_failed_jobs_errors_and_stopped_horizon_are_reported(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessMediaJob']),
            'exception' => 'boom', 'failed_at' => now(),
        ]);
        $line = '['.now()->format('Y-m-d H:i:s').'] production.ERROR: Quelque chose a cassé';
        file_put_contents($this->dir.'/logs/laravel.log', implode("\n", array_fill(0, 25, $line))."\n");
        $this->horizon(null);

        $this->artisan('memorylane:health-check')->assertFailed();

        $this->assertEqualsCanonicalizing(['failed_jobs', 'errors', 'horizon'], $this->problemKeys());
    }

    public function test_alert_mail_lists_the_problems(): void
    {
        $html = (string) (new SystemHealthAlert([['label' => 'Sauvegarde de la base', 'detail' => 'Aucun dump trouvé.']]))
            ->toMail($this->admin)->render();

        $this->assertStringContainsString('Sauvegarde de la base', $html);
        $this->assertStringContainsString('Aucun dump trouvé.', $html);
    }

    public function test_admin_widget_shows_and_refreshes_the_checks(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SystemHealth::class)
            ->assertSee('Pas encore vérifié')
            ->call('runNow')
            ->assertSee('Sauvegarde des photos (OVH)')
            ->assertSee('Tout est au vert');
    }
}
