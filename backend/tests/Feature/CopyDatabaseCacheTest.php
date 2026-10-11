<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CopyDatabaseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_copies_live_entries_with_their_remaining_ttl(): void
    {
        Cache::store('database')->put('geocode:brest', ['latitude' => 48.39, 'longitude' => -4.49], 3600);
        Cache::store('database')->forever('namedays:10-11', ['firmin']);

        $this->artisan('cache:copy-from-database', ['--to' => 'array'])
            ->expectsOutputToContain('2 entrée(s)')
            ->assertSuccessful();

        $this->assertSame(['latitude' => 48.39, 'longitude' => -4.49], Cache::store('array')->get('geocode:brest'));
        $this->assertSame(['firmin'], Cache::store('array')->get('namedays:10-11'));
    }
}
