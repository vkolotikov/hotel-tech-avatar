<?php

use App\Models\Vertical;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hotel = Vertical::firstOrCreate(
            ['slug' => 'hotel'],
            ['name' => 'Hotel Concierge Suite', 'is_active' => true, 'launched_at' => now()]
        );

        DB::table('agents')->whereNull('vertical_id')->update(['vertical_id' => $hotel->id]);
    }

    public function down(): void
    {
        DB::table('agents')->update(['vertical_id' => null]);
    }
};
