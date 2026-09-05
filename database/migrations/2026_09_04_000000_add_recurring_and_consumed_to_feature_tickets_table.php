<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'feature_tickets';

    private const INDEX = 'feature_tickets_allocation_index';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'recurring')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->boolean('recurring')->default(true)->after('charges');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'consumed')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->decimal('consumed')->unsigned()->default(0)->after('recurring');
            });
        }

        if ($this->hasAllocationIndex() !== true) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['subscriber_type', 'subscriber_id', 'feature_id', 'recurring', 'expired_at', 'id'],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasAllocationIndex() !== false) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->dropIndex(self::INDEX);
                });
            } catch (QueryException) {
                // Already absent. Only reachable where the index cannot be introspected.
            }
        }

        if (Schema::hasColumn(self::TABLE, 'consumed')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('consumed');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'recurring')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('recurring');
            });
        }
    }

    /**
     * Schema::hasIndex() is not available across the whole supported Laravel range
     * (the CI matrix still covers 9.x). Returns null when the framework cannot be
     * asked, so callers fall back to attempting the operation.
     */
    private function hasAllocationIndex(): ?bool
    {
        $builder = Schema::getFacadeRoot();

        return method_exists($builder, 'hasIndex')
            ? $builder->hasIndex(self::TABLE, self::INDEX)
            : null;
    }
};
