<?php

namespace App\Actions;

use App\Core\Ai\PublicEndpoint;
use App\Models\AiProviderConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ProxyProviderChat
{
    public function __construct(private PublicEndpoint $endpoint) {}

    /**
     * Send a chat completion to one of the user's providers with the key stored here, so the key
     * never reaches the browser. Returns the provider's answer still streaming (server-sent events).
     * Only for providers with a key on a public https endpoint; local ones (Ollama) are called by
     * the browser directly.
     *
     * @param  array{model: string, messages: list<array<string, mixed>>, tools?: list<array<string, mixed>>, temperature?: float, max_tokens?: int}  $body
     */
    public function handle(AiProviderConfig $provider, array $body): Response
    {
        if (! $provider->enabled) {
            throw ValidationException::withMessages(['provider' => 'This provider is switched off.']);
        }
        if (blank($provider->api_key)) {
            throw ValidationException::withMessages(['provider' => 'This provider has no key stored here; the browser calls it directly.']);
        }

        $url = rtrim($provider->base_url, '/').'/chat/completions';
        $ip = $this->endpoint->resolve($url);
        if ($ip === null) {
            throw ValidationException::withMessages(['provider' => 'Keys are only sent to public https endpoints.']);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return Http::withToken($provider->api_key)
            // Connect to the address that was checked, and don't follow redirects elsewhere.
            ->withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]]])
            ->withoutRedirecting()
            ->connectTimeout(10)
            ->timeout(300)
            ->post($url, [...$body, 'stream' => true, 'stream_options' => ['include_usage' => true]]);
    }

    /**
     * Hide the key if a provider echoes it back (some error messages do).
     */
    public function redact(AiProviderConfig $provider, string $text): string
    {
        return blank($provider->api_key) ? $text : str_replace($provider->api_key, '[key hidden]', $text);
    }
}
