<?php

namespace Database\Seeders;

use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        for ($day = 0; $day < 7; $day++) {
            for ($hour = 9; $hour < 18; $hour++) {
                $start = $today->addDays($day)->setHour($hour)->utc();
                Slot::firstOrCreate(
                    ['starts_at' => $start->format('Y-m-d H:i:s')],
                    ['ends_at' => $start->addHour()],
                );
            }
        }
    }
}
