<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Target flow ID and name for the Enlace Test flow.
     */
    protected const TARGET_FLOW_ID = 'a327cdfe-606d-4d40-a921-63f10315e8f7';
    protected const TARGET_FLOW_NAME = 'Enlace Test';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $flows = DB::table('flows')
            ->where('id', self::TARGET_FLOW_ID)
            ->orWhere('name', self::TARGET_FLOW_NAME)
            ->get();

        foreach ($flows as $flow) {
            $applicability = [];
            if (! empty($flow->channel_applicability)) {
                $decoded = is_string($flow->channel_applicability)
                    ? json_decode($flow->channel_applicability, true)
                    : (array) $flow->channel_applicability;

                if (is_array($decoded)) {
                    $applicability = $decoded;
                }
            }

            if (! in_array('webchat', $applicability, true)) {
                $applicability[] = 'webchat';
            }
            if (! in_array('whatsapp', $applicability, true)) {
                $applicability[] = 'whatsapp';
            }

            DB::table('flows')
                ->where('id', $flow->id)
                ->update([
                    'channel_applicability' => json_encode(array_values(array_unique($applicability))),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $flows = DB::table('flows')
            ->where('id', self::TARGET_FLOW_ID)
            ->orWhere('name', self::TARGET_FLOW_NAME)
            ->get();

        foreach ($flows as $flow) {
            $applicability = [];
            if (! empty($flow->channel_applicability)) {
                $decoded = is_string($flow->channel_applicability)
                    ? json_decode($flow->channel_applicability, true)
                    : (array) $flow->channel_applicability;

                if (is_array($decoded)) {
                    $applicability = $decoded;
                }
            }

            $applicability = array_values(array_filter($applicability, fn ($type) => $type !== 'whatsapp'));
            if (empty($applicability)) {
                $applicability = ['webchat'];
            }

            DB::table('flows')
                ->where('id', $flow->id)
                ->update([
                    'channel_applicability' => json_encode($applicability),
                    'updated_at' => now(),
                ]);
        }
    }
};
