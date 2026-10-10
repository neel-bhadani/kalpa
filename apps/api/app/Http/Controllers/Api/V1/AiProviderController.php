<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SaveAiProvider;
use App\Enums\AiProviderKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiProviderResource;
use App\Models\AiProviderConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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
