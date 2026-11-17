<?php

namespace Tests;

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Services\Routing\ConversationQueueService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
    }

    protected function grantChannelAccess(Channel $channel, User ...$users): void
    {
        foreach ($users as $user) {
            if (! $user->hasRole('super_admin')) {
                $user->channels()->syncWithoutDetaching([$channel->id]);
            }
        }
    }

    protected function addToDefaultTeam(User ...$users): Team
    {
        $team = app(ConversationQueueService::class)->defaultTeam();

        foreach ($users as $user) {
            $user->teams()->syncWithoutDetaching([$team->id]);
        }

        return $team;
    }
}
