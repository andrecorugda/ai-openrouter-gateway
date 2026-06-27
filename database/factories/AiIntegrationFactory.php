<?php

declare(strict_types=1);

namespace Andre\AiGateway\Database\Factories;

use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiIntegration>
 */
class AiIntegrationFactory extends Factory
{
    protected $model = AiIntegration::class;

    /**
     * @return array<string,mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'slug' => str($name)->slug('_').'_'.$this->faker->unique()->numberBetween(1, 9999),
            'name' => ucwords($name),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'provider' => 'openrouter',
            'visibility' => 'internal',
            'supports_vision' => false,
            'supports_tools' => false,
            'rate_limit_per_minute' => null,
            'max_daily_cost_usd' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => 'public']);
    }

    /**
     * Attach an active version after creating the integration.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function withVersion(array $attributes = []): static
    {
        return $this->afterCreating(function (AiIntegration $integration) use ($attributes) {
            AiIntegrationVersion::query()->create(array_merge([
                'ai_integration_id' => $integration->id,
                'version_number' => 1,
                'is_active' => true,
                'system_prompt' => 'You are a helpful assistant. {{question}}',
                'system_prompt_cacheable' => true,
                'models' => [config('ai-gateway.default_model', 'anthropic/claude-sonnet-4')],
                'default_params' => ['max_tokens' => 1024],
                'prompt_args' => [
                    ['name' => 'question', 'type' => 'string', 'required' => true, 'default' => null, 'description' => null],
                ],
                'server_tools' => null,
            ], $attributes));
        });
    }
}
