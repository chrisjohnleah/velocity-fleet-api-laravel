<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private function table(): string
    {
        $table = config('velocity-fleet.geofencing.events_table', 'velocity_fleet_geofence_events');

        return is_string($table) ? $table : 'velocity_fleet_geofence_events';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('geofence_id');
            $table->string('customer_id')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->unsignedInteger('dwell_seconds')->nullable();
            $table->timestamps();

            $table->index(['geofence_id', 'device_id', 'exited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
