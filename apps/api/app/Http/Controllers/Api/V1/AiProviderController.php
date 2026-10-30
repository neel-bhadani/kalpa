<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ProxyProviderChat;
use App\Actions\SaveAiProvider;
use App\Enums\AiProviderKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiProviderResource;
use App\Models\AiProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My own AI providers (OpenAI-compatible APIs, Ollama). Keys are stored encrypted and never returned.
 */
class AiProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $providers = AiProviderConfig::where('user_id', $request->user()->id)->orderBy('name')->get();

        return response()->json(['data' => AiProviderResource::collection($providers)]);
    }

    public function store(Request $request, SaveAiProvider $save): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return (new AiProviderResource($save->handle($request->user(), $data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, AiProviderConfig $provider, SaveAiProvider $save): AiProviderResource
    {
        $this->ensureMine($request, $provider);

        return new AiProviderResource($save->handle($request->user(), $request->validate($this->rules(required: false)), $provider));
    }

    public function destroy(Request $request, AiProviderConfig $provider): Response
    {
        $this->ensureMine($request, $provider);
        $provider->delete();

        return response()->noContent();
    }

    /**
     * One chat completion through the server, for providers whose key is stored here.
     * The answer streams back as server-sent events, exactly as the provider sends them.
     */
    public function chat(Request $request, AiProviderConfig $provider, ProxyProviderChat $proxy): StreamedResponse|JsonResponse
    {
        $this->ensureMine($request, $provider);

        $request->validate([
            'model' => ['required', 'string', 'max:200'],
            'messages' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'messages.*' => ['array:role,content,tool_calls,tool_call_id,name'],
            'messages.*.role' => ['required', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:100000'],
            'messages.*.tool_calls' => ['sometimes', 'array', 'max:20'],
            'tools' => ['sometimes', 'array', 'list', 'max:64'],
            'tools.*' => ['array:type,function'],
            'tools.*.type' => ['required', 'in:function'],
            'tools.*.function' => ['required', 'array:name,description,parameters'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'max_tokens' => ['sometimes', 'integer', 'between:1,8192'],
            'reasoning_effort' => ['sometimes', 'in:none,minimal,low,medium,high'],
        ]);

        try {
            $upstream = $proxy->handle($provider, $request->only(['model', 'messages', 'tools', 'temperature', 'max_tokens', 'reasoning_effort']));
        } catch (ConnectionException) {
            return response()->json(['message' => 'Could not reach the provider.'], 502);
        }

        if ($upstream->failed()) {
            return response()->json([
                'message' => 'The provider refused the request.',
                'upstream_status' => $upstream->status(),
                'upstream_error' => $proxy->redact($provider, Str::limit($upstream->body(), 1000)),
            ], 502);
        }

        $body = $upstream->toPsrResponse()->getBody();
        $key = (string) $provider->api_key;

        return response()->stream(function () use ($body, $key, $proxy, $provider) {
            $tail = '';
            while (! $body->eof()) {
                $text = $proxy->redact($provider, $tail.$body->read(8192));
                // Hold back a tail that could be the start of the key, until the next read.
                $cut = max(0, strlen($text) - (strlen($key) - 1));
                echo substr($text, 0, $cut);
                $tail = substr($text, $cut);
                flush();
            }
            echo $tail;
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    private function rules(bool $required): array
    {
        $sometimes = $required ? 'required' : 'sometimes';

        return [
            'provider' => [$sometimes, Rule::enum(AiProviderKind::class)],
            'name' => [$sometimes, 'string', 'max:100'],
            'base_url' => [$sometimes, 'url:http,https', 'max:500'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'models' => ['sometimes', 'array', 'list', 'max:50'],
            'models.*' => ['string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    private function ensureMine(Request $request, AiProviderConfig $provider): void
    {
        abort_if($provider->user_id !== $request->user()->id, 404);
    }
}
