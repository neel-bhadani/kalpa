<?php

use App\Core\Ai\PublicEndpoint;
use App\Models\AiProviderConfig;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const PROXY_KEY = 'sk-test-proxy-1234567890SECRET';

beforeEach(function () {
    // api.example.com stands for a public provider (no DNS in tests).
    $this->app->instance(PublicEndpoint::class, new class extends PublicEndpoint
    {
        protected function lookup(string $host): array
        {
            return $host === 'api.example.com' ? ['93.184.215.14'] : [];
        }
    });
    RateLimiter::clear('ai-chat');
});

function proxyProvider($user, array $overrides = []): AiProviderConfig
{
    return AiProviderConfig::create([
        'user_id' => $user->id, 'provider' => 'openai_compatible', 'name' => 'Example', 'base_url' => 'https://api.example.com/v1',
        'api_key' => PROXY_KEY, 'models' => ['small-model'], 'enabled' => true, ...$overrides,
    ]);
}

function chatBody(array $overrides = []): array
{
    return ['model' => 'small-model', 'messages' => [['role' => 'user', 'content' => 'Hello']], ...$overrides];
}

it('streams the provider answer and sends the key only to the provider', function () {
    $ada = owner();
    $provider = proxyProvider($ada);
    $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\ndata: {\"choices\":[{\"delta\":{\"content\":\" there\"}}]}\n\ndata: [DONE]\n\n";
    Http::fake(['api.example.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody([
        'tools' => [['type' => 'function', 'function' => ['name' => 'notes_append', 'description' => 'Add notes', 'parameters' => ['type' => 'object']]]],
    ]));

    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    expect($response->streamedContent())->toBe($sse)
        ->and(json_encode($response->headers->all()))->not->toContain(PROXY_KEY);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.example.com/v1/chat/completions'
        && $request->header('Authorization') === ['Bearer '.PROXY_KEY]
        && $request['stream'] === true
        && $request['tools'][0]['function']['name'] === 'notes_append'
        && $request['messages'] === [['role' => 'user', 'content' => 'Hello']]);
});

it('hides the key if the provider echoes it back', function () {
    $ada = owner();
    $provider = proxyProvider($ada);
    Http::fake(['api.example.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'Incorrect API key provided: '.PROXY_KEY]], 401)
        ->push('data: {"echo":"'.PROXY_KEY.'"}'."\n\n", 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $refused = $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody())
        ->assertStatus(502)
        ->assertJsonPath('upstream_status', 401);
    expect($refused->getContent())->not->toContain(PROXY_KEY)->toContain('[key hidden]');

    $streamed = $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody())->assertOk()->streamedContent();
    expect($streamed)->not->toContain(PROXY_KEY)->toContain('[key hidden]');
});

it('forwards only chat fields', function () {
    $ada = owner();
    $provider = proxyProvider($ada);
    Http::fake(['api.example.com/*' => Http::response("data: [DONE]\n\n")]);

    $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody(['base_url' => 'https://evil.test', 'api_key' => 'x', 'temperature' => 0.2]))
        ->assertOk()
        ->streamedContent();
    Http::assertSent(fn (ClientRequest $request) => array_keys($request->data()) === ['model', 'messages', 'temperature', 'stream', 'stream_options']);

    // Extra keys inside a message are refused, not passed on.
    $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody(['messages' => [['role' => 'user', 'content' => 'x', 'url' => 'y']]]))
        ->assertUnprocessable();
});

it('is rate limited per user', function () {
    $ada = owner();
    $provider = proxyProvider($ada);
    Http::fake(['api.example.com/*' => Http::response("data: [DONE]\n\n")]);

    foreach (range(1, 30) as $i) {
        $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody())->assertOk()->streamedContent();
    }
    $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$provider->id}/chat", chatBody())->assertTooManyRequests();

    // Someone else still can.
    $bob = owner('Bob');
    $this->actingAs($bob)->postJson('/api/v1/ai/providers/'.proxyProvider($bob)->id.'/chat', chatBody())->assertOk()->streamedContent();
});

it('refuses other people, keyless providers and non-public endpoints', function () {
    $ada = owner();
    Http::fake();

    $bob = owner('Bob');
    $this->actingAs($bob)->postJson('/api/v1/ai/providers/'.proxyProvider($ada)->id.'/chat', chatBody())->assertNotFound();

    $local = proxyProvider($ada, ['base_url' => 'http://localhost:11434/v1', 'api_key' => null]);
    $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$local->id}/chat", chatBody())->assertJsonValidationErrors('provider');

    $off = proxyProvider($ada, ['enabled' => false]);
    $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$off->id}/chat", chatBody())->assertJsonValidationErrors('provider');

    foreach (['http://api.example.com/v1', 'https://10.0.0.5/v1', 'https://127.0.0.1/v1', 'https://169.254.169.254/v1', 'https://[::1]/v1', 'https://seaweedfs:8333/v1'] as $url) {
        $internal = proxyProvider($ada, ['base_url' => $url]);
        $this->actingAs($ada)->postJson("/api/v1/ai/providers/{$internal->id}/chat", chatBody())->assertJsonValidationErrors('provider');
    }

    Http::assertNothingSent();
});
