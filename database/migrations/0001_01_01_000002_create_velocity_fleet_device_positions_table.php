<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private function table(): string
    {
        return 'velocity_fleet_device_positions';
    }

    private function connection(): ?string
    {
        $connection = config('velocity-fleet.history.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('customer_id')->index();
            $table->string('device_id');
            $table->string('service_id')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 11, 7)->nullable();
            $table->integer('speed')->nullable();
            $table->decimal('direction', 6, 2)->nullable();
            $table->string('ignition')->nullable();
            $table->text('driver_name')->nullable();
            $table->text('vehicle_registration')->nullable();
            $table->text('mobile_phone')->nullable();
            $table->string('signal_strength_color')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'device_id', 'recorded_at'], 'vf_device_positions_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
