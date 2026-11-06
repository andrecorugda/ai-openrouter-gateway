<?php

declare(strict_types=1);

use Andre\AiGateway\Filament\Pages\ApiDocs;
use Andre\AiGateway\Filament\Pages\ApiTokens;
use Andre\AiGateway\Filament\Pages\GeneralSettings;
use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Andre\AiGateway\Filament\Resources\AiInvocationResource;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Support\Settings;
use Andre\AiGateway\Tests\Fixtures\AllowsAdmins;
use Andre\AiGateway\Tests\Fixtures\PanelUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

/*
 * GHSA-57hh-5c98-83r8: config('ai-gateway.filament.authorize') was documented
 * as the gate for the whole admin UI but never enforced. These tests pin that
 * a panel user who fails the gate reaches none of the gateway's screens.
 */

function panelUser(string $email): PanelUser
{
    return PanelUser::query()->create([
        'name' => $email,
        'email' => $email,
        'password' => bcrypt('secret'),
    ]);
}

/** Every gateway screen, by its URL in the test panel. */
function gatewayUrls(): array
{
    return [
        'integrations' => AiIntegrationResource::getUrl('index'),
        'new integration' => AiIntegrationResource::getUrl('create'),
        'invocations' => AiInvocationResource::getUrl('index'),
        'settings' => GeneralSettings::getUrl(),
        'api tokens' => ApiTokens::getUrl(),
        'api docs' => ApiDocs::getUrl(),
    ];
}

/** @return list<class-string> */
function gatewayScreens(): array
{
    return [
        AiIntegrationResource::class,
        AiInvocationResource::class,
        GeneralSettings::class,
        ApiTokens::class,
        ApiDocs::class,
    ];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Settings::set('api_enabled', true);
    Gate::define('access-ai-gateway', fn (PanelUser $user): bool => $user->email === 'admin@example.test');
});

it('denies every gateway screen to a panel user who fails the ability gate', function (): void {
    config()->set('ai-gateway.filament.authorize', 'access-ai-gateway');
    $this->actingAs(panelUser('staff@example.test'));

    foreach (gatewayScreens() as $screen) {
        expect($screen::canAccess())->toBeFalse("{$screen} is reachable");
    }

    foreach (gatewayUrls() as $name => $url) {
        $this->get($url)->assertForbidden();
    }
});

it('denies the edit page of an existing integration to a user who fails the gate', function (): void {
    config()->set('ai-gateway.filament.authorize', 'access-ai-gateway');
    $integration = AiIntegration::query()->create(['slug' => 'faq', 'name' => 'FAQ']);
    $this->actingAs(panelUser('staff@example.test'));

    $this->get(AiIntegrationResource::getUrl('edit', ['record' => $integration]))->assertForbidden();
});

it('lets a user who passes the ability gate in', function (): void {
    config()->set('ai-gateway.filament.authorize', 'access-ai-gateway');
    $this->actingAs(panelUser('admin@example.test'));

    foreach (gatewayScreens() as $screen) {
        expect($screen::canAccess())->toBeTrue("{$screen} is blocked");
    }

    // The same URLs are not forbidden to this user, so the 403s above come
    // from the gate, not the panel. (Full page rendering needs a real app
    // layout, so only the authorization outcome is asserted here.)
    foreach (gatewayUrls() as $name => $url) {
        expect($this->get($url)->status())->not->toBe(403, "{$name} is forbidden");
    }
});

it('enforces an invokable class gate, the config:cache-safe form', function (): void {
    config()->set('ai-gateway.filament.authorize', AllowsAdmins::class);

    $this->actingAs(panelUser('staff@example.test'));
    expect(ApiTokens::canAccess())->toBeFalse();

    $this->actingAs(panelUser('admin@example.test'));
    expect(ApiTokens::canAccess())->toBeTrue();
});

it('enforces a closure gate', function (): void {
    config()->set('ai-gateway.filament.authorize', fn (?PanelUser $user): bool => $user?->email === 'admin@example.test');

    $this->actingAs(panelUser('staff@example.test'));
    expect(GeneralSettings::canAccess())->toBeFalse();
});

it('fails closed on a gate value it cannot evaluate', function (): void {
    config()->set('ai-gateway.filament.authorize', ['not', 'a', 'gate']);
    $this->actingAs(panelUser('admin@example.test'));

    expect(ApiTokens::canAccess())->toBeFalse();
});

it('keeps the documented default: no gate means any panel user', function (?string $gate): void {
    config()->set('ai-gateway.filament.authorize', $gate);
    $this->actingAs(panelUser('staff@example.test'));

    foreach (gatewayScreens() as $screen) {
        expect($screen::canAccess())->toBeTrue("{$screen} is blocked");
    }
})->with(['null' => [null], 'empty env value' => ['']]);

it('still applies a host app policy on top of the gate', function (): void {
    config()->set('ai-gateway.filament.authorize', 'access-ai-gateway');
    Gate::policy(AiIntegration::class, DenyAllIntegrationsPolicy::class);
    $this->actingAs(panelUser('admin@example.test'));

    expect(AiIntegrationResource::canAccess())->toBeFalse();
});

class DenyAllIntegrationsPolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}
