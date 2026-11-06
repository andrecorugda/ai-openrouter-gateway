<?php

declare(strict_types=1);

namespace Andre\AiGateway\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

/** A user every test panel admits, so only the gateway gate decides access. */
class PanelUser extends User implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
