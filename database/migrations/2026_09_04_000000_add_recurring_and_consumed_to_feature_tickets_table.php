<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('feature_tickets', 'recurring')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->boolean('recurring')->default(true)->after('charges');
            });
        }

        if (! Schema::hasColumn('feature_tickets', 'consumed')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->decimal('consumed')->unsigned()->default(0)->after('recurring');
            });
        }

        if (! Schema::hasIndex('feature_tickets', 'feature_tickets_allocation_index')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->index(
                    ['subscriber_type', 'subscriber_id', 'feature_id', 'recurring', 'expired_at', 'id'],
                    'feature_tickets_allocation_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('feature_tickets', 'feature_tickets_allocation_index')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->dropIndex('feature_tickets_allocation_index');
            });
        }

        if (Schema::hasColumn('feature_tickets', 'consumed')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->dropColumn('consumed');
            });
        }

        if (Schema::hasColumn('feature_tickets', 'recurring')) {
            Schema::table('feature_tickets', function (Blueprint $table): void {
                $table->dropColumn('recurring');
            });
        }
    }
};
