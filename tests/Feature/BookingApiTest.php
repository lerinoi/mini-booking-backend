<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Database\Seeders\ScheduleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-06-01 08:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function slot(): Slot
    {
        return Slot::create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    }

    private function payload(Slot $slot): array
    {
        return ['slot_id' => $slot->id, 'customer_name' => 'Test Customer', 'customer_email' => 'test@example.com'];
    }

    public function test_future_slot_can_be_booked(): void
    {
        $slot = $this->slot();
        $response = $this->postJson('/api/bookings', $this->payload($slot))->assertCreated()->assertJsonStructure(['id']);
        $this->assertDatabaseHas('bookings', ['id' => $response->json('id'), 'slot_id' => $slot->id, 'cancelled_at' => null]);
    }

    public function test_duplicate_booking_conflicts(): void
    {
        $slot = $this->slot();
        $this->postJson('/api/bookings', $this->payload($slot))->assertCreated();
        $this->postJson('/api/bookings', $this->payload($slot))->assertConflict();
        $this->assertSame(1, Booking::whereNull('cancelled_at')->count());
    }

    public function test_cancel_and_rebook_preserves_history(): void
    {
        $slot = $this->slot();
        $id = $this->postJson('/api/bookings', $this->payload($slot))->json('id');
        $this->getJson('/api/slots')->assertJsonPath('0.status', 'booked')->assertJsonPath('0.active_booking_id', $id);
        $this->deleteJson("/api/bookings/$id")->assertNoContent();
        $this->assertNotNull(Booking::findOrFail($id)->cancelled_at);
        $this->deleteJson("/api/bookings/$id")->assertNotFound();
        $this->deleteJson('/api/bookings/999999')->assertNotFound();
        $this->getJson('/api/slots')->assertJsonPath('0.status', 'available')->assertJsonPath('0.active_booking_id', null);
        $this->postJson('/api/bookings', $this->payload($slot))->assertCreated();
        $this->assertDatabaseCount('bookings', 2);
        $this->assertSame(1, Booking::whereNull('cancelled_at')->count());
    }

    public function test_invalid_fields_are_reported(): void
    {
        $this->postJson('/api/bookings', [])->assertUnprocessable()->assertJsonValidationErrors(['slot_id', 'customer_name', 'customer_email']);
        $this->postJson('/api/bookings', ['slot_id' => 99999, 'customer_name' => str_repeat('x', 256), 'customer_email' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slot_id', 'customer_name', 'customer_email']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_started_slot_is_past_even_when_booked(): void
    {
        $slot = $this->slot();
        $id = $this->postJson('/api/bookings', $this->payload($slot))->json('id');
        $this->travelTo($slot->starts_at);
        $this->getJson('/api/slots')->assertJsonPath('0.status', 'past')->assertJsonPath('0.active_booking_id', $id);
        $this->deleteJson("/api/bookings/$id")->assertNoContent();
        $this->postJson('/api/bookings', $this->payload($slot))->assertConflict();
        $this->travel(1)->seconds();
        $this->postJson('/api/bookings', $this->payload($slot))->assertConflict();
    }

    public function test_slots_are_sorted_private_and_loaded_without_n_plus_one(): void
    {
        $this->seed(ScheduleSeeder::class);
        Booking::create($this->payload(Slot::first()));
        DB::enableQueryLog();
        $data = $this->getJson('/api/slots')->assertOk()->json();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select')));
        $this->assertSame('2030-06-01T09:00:00+03:00', $data[0]['starts_at']);
        $this->assertSame('2030-06-01T10:00:00+03:00', $data[0]['ends_at']);
        $this->assertSame('Europe/Moscow', $data[0]['timezone']);
        $this->assertSame(['id', 'starts_at', 'ends_at', 'timezone', 'status', 'active_booking_id'], array_keys($data[0]));
        $dates = array_column($data, 'starts_at');
        $sorted = $dates; sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_seeder_is_idempotent_and_extends_schedule_without_losing_history(): void
    {
        $this->seed(ScheduleSeeder::class);
        $this->assertDatabaseCount('slots', 63);
        $this->assertDatabaseHas('slots', ['starts_at' => '2030-06-01 06:00:00', 'ends_at' => '2030-06-01 07:00:00']);
        $this->assertDatabaseHas('slots', ['starts_at' => '2030-06-07 14:00:00', 'ends_at' => '2030-06-07 15:00:00']);
        $booking = Booking::create($this->payload(Slot::first()));
        $this->seed(ScheduleSeeder::class);
        $this->assertDatabaseCount('slots', 63);
        $this->travel(1)->days();
        $this->seed(ScheduleSeeder::class);
        $this->assertDatabaseCount('slots', 72);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'cancelled_at' => null]);
        $this->assertDatabaseHas('slots', ['starts_at' => '2030-06-08 14:00:00']);
    }

    public function test_database_rejects_two_active_bookings(): void
    {
        $slot = $this->slot();
        Booking::create($this->payload($slot));
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed: bookings.slot_id');
        Booking::create($this->payload($slot));
    }

    public function test_database_rejects_duplicate_slot_start(): void
    {
        $slot = $this->slot();
        $this->expectException(QueryException::class);
        Slot::create(['starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at]);
    }

    public function test_database_enforces_booking_foreign_key(): void
    {
        $this->expectException(QueryException::class);
        Booking::create(['slot_id' => 9999, 'customer_name' => 'Test', 'customer_email' => 'test@example.com']);
    }
}
