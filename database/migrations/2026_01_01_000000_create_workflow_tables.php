<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('workflow.connection');
    }

    public function up(): void
    {
        $tables = config('workflow.tables');

        Schema::create($tables['instances'], function (Blueprint $table) {
            $table->id();
            $table->string('workflow', 100);
            $table->string('subject_type');
            $table->string('subject_id', 64);
            $table->string('state', 100);
            // active, suspended (subject trashed), completed (final state), cancelled
            $table->string('status', 20)->default('active');
            // Bumped every time a state is entered, so votes and tasks from an
            // earlier visit to the same state never count again.
            $table->unsignedInteger('round')->default(1);
            $table->json('context')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('state_entered_at')->nullable();
            $table->timestamp('timer_at')->nullable();
            $table->string('timer_transition', 100)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow', 'subject_type', 'subject_id'], 'workflow_instances_subject_unique');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['workflow', 'state', 'status']);
            $table->index(['status', 'timer_at']);
        });

        Schema::create($tables['history'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('instance_id')->constrained($tables['instances'])->cascadeOnDelete();
            $table->string('transition', 100);
            $table->string('from_state', 100)->nullable();
            $table->string('to_state', 100);
            $table->unsignedInteger('round')->default(1);
            $table->unsignedBigInteger('actor_id')->nullable();
            // user, system (timers, automatic transitions) or sync (outside change)
            $table->string('actor_kind', 20)->default('user');
            $table->text('comment')->nullable();
            $table->json('input')->nullable();
            $table->unsignedBigInteger('seconds_in_state')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'id']);
            $table->index('actor_id');
        });

        Schema::create($tables['approvals'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('instance_id')->constrained($tables['instances'])->cascadeOnDelete();
            $table->string('transition', 100);
            $table->unsignedInteger('round');
            $table->unsignedBigInteger('user_id');
            $table->string('decision', 10);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'transition', 'round', 'user_id'], 'workflow_approvals_vote_unique');
        });

        Schema::create($tables['tasks'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('instance_id')->constrained($tables['instances'])->cascadeOnDelete();
            $table->string('state', 100);
            $table->unsignedInteger('round');
            $table->string('name', 100)->default('default');
            $table->string('label')->nullable();
            // user (assignee = user id), role or permission (assignee = name)
            $table->string('assignee_type', 20);
            $table->string('assignee', 191);
            $table->unsignedBigInteger('claimed_by')->nullable();
            // open, completed, cancelled
            $table->string('status', 20)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'assignee_type', 'assignee']);
            $table->index(['status', 'due_at']);
            $table->index(['instance_id', 'status']);
            $table->index('claimed_by');
        });
    }

    public function down(): void
    {
        $tables = config('workflow.tables');

        Schema::dropIfExists($tables['tasks']);
        Schema::dropIfExists($tables['approvals']);
        Schema::dropIfExists($tables['history']);
        Schema::dropIfExists($tables['instances']);
    }
};
