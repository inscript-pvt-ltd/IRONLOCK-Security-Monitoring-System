<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guards', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('employee_code', 50)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 100)->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable();

            // SIA Licence Requirements
            $table->string('sia_licence_number', 50)->unique();
            $table->date('sia_licence_expiry');
            $table->string('sia_licence_type', 100)->nullable();

            // Security & Session Management
            $table->string('device_identifier')->nullable();
            $table->string('device_name')->nullable();
            $table->char('active_session_token_id', 36)->nullable();
            $table->timestamp('account_locked_at')->nullable();
            $table->integer('failed_login_count')->default(0);

            // Employment Details
            $table->date('hire_date')->nullable();
            $table->enum('employment_status', ['active', 'inactive', 'suspended'])->default('active');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('sia_licence_expiry', 'idx_guards_sia_licence_expiry');
            $table->index('employment_status', 'idx_guards_employment_status');
            $table->index('employee_code', 'idx_guards_employee_code');
            $table->index('username', 'idx_guards_username');
            $table->index('status', 'idx_guards_status');

            // Foreign Keys
            $table->foreign('created_by', 'fk_guards_created_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guards');
    }
};