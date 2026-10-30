// A brain on an OpenAI-compatible endpoint: Ollama, the llama.cpp server, or a provider with the
// user's own key. How the request travels is up to `post`: directPost() calls a local endpoint from
// the browser; for a key stored on the Kalpa server, the web app posts to the server's proxy
// (POST /api/v1/ai/providers/{id}/chat), so the key never reaches the browser.

import { CompletionBrain, readSse, ToolsNotSupported, type CompletionChunk, type CompletionRequest, type ToolMode } from './completion-brain'

export type PostChat = (body: Record<string, unknown>, signal?: AbortSignal) => Promise<Response>

export type OpenAICompatibleOptions = {
  model: string
  post: PostChat
  tools?: ToolMode
  now?: () => number
}

export class OpenAICompatibleBrain extends CompletionBrain {
  constructor(private config: OpenAICompatibleOptions) {
    super({ model: config.model, tools: config.tools, now: config.now })
  }

  protected override async complete(request: CompletionRequest, signal?: AbortSignal): Promise<AsyncIterable<CompletionChunk>> {
    const { maxTokens, ...rest } = request
    // Qwen3 thinks before answering unless told not to; Ollama ignores "/no_think" but takes this.
    const noThinking = /qwen3/i.test(this.config.model) ? { reasoning_effort: 'none' } : {}
    const response = await this.config.post({ model: this.config.model, ...rest, ...noThinking, ...(maxTokens ? { max_tokens: maxTokens } : {}), stream: true, stream_options: { include_usage: true } }, signal)
    if (!response.ok) throw await endpointError(response, Boolean(request.tools))
    if (!response.body) throw new Error('The model server sent no answer.')
    return chunks(response.body)
  }
}

// Calls `${baseUrl}/chat/completions` straight from the browser (a local endpoint, no key).
export function directPost(baseUrl: string): PostChat {
  return (body, signal) => fetch(`${baseUrl.replace(/\/+$/, '')}/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  })
}

async function* chunks(body: ReadableStream<Uint8Array>): AsyncIterable<CompletionChunk> {
  for await (const data of readSse(body)) {
    const chunk = JSON.parse(data) as CompletionChunk & { error?: { message?: string } }
    if (chunk.error) throw new Error(`The model server failed: ${chunk.error.message ?? 'unknown error'}`)
    yield chunk
  }
}

// The Kalpa proxy answers 502 with the provider's own status in upstream_status.
async function endpointError(response: Response, sentTools: boolean): Promise<Error> {
  const body = await response.json().catch(() => ({})) as { message?: string; upstream_status?: number; upstream_error?: string; error?: { message?: string } | string }
  const status = body.upstream_status ?? response.status
  const detail = body.upstream_error ?? (typeof body.error === 'string' ? body.error : body.error?.message) ?? body.message ?? response.statusText
  if (status === 400 && sentTools) return new ToolsNotSupported(detail)
  if (response.status === 429) return new Error('Too many assistant messages for now. Wait a minute and try again.')
  return new Error(`The model server answered ${status}: ${detail}`)
}
