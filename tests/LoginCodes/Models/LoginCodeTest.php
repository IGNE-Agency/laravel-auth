<?php

declare(strict_types=1);

use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\Tests\Fixtures\User;

it('prunes codes that expired or were used more than a day ago', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $codes = LoginCode::factory()->for($user, 'authenticatable');
    $active = $codes->create();
    $recentlyExpired = $codes->create(['expires_at' => now()->subHours(23)]);
    $recentlyUsed = $codes->create(['consumed_at' => now()->subHours(23)]);
    $codes->create(['expires_at' => now()->subHours(25)]);
    $codes->create(['consumed_at' => now()->subHours(25)]);

    $this->artisan('model:prune', ['--model' => [LoginCode::class]])->assertSuccessful();

    expect(LoginCode::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active, $recentlyExpired, $recentlyUsed])->pluck('id')->sort()->values()->all());
});

it('never shows the code hash when serialized', function (): void {
    $loginCode = LoginCode::factory()->for(User::factory()->create(), 'authenticatable')->create();

    expect($loginCode->toArray())->not->toHaveKey('code_hash');
});
