<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private function table(): string
    {
        $table = config('velocity-fleet.geofencing.table', 'velocity_fleet_geofences');

        return is_string($table) ? $table : 'velocity_fleet_geofences';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('circle');
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lon', 10, 7)->nullable();
            $table->decimal('radius_m', 12, 2)->nullable();
            $table->json('points')->nullable();
            $table->string('customer_id')->nullable();
            $table->unsignedBigInteger('device_group_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
