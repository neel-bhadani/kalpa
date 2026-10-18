// A brain on this device's GPU: WebLLM runs an MLC build of the model through WebGPU, in a Web
// Worker. Only for devices with WebGPU. WebLLM downloads and caches the model's shards itself
// (Cache Storage), and checks them against the build's own list. It has no native tool calls for
// Qwen3, so tools use the JSON text format.

import type { MLCEngineInterface } from '@mlc-ai/web-llm'
import { CompletionBrain, type CompletionChunk, type CompletionRequest } from './completion-brain'

export type WebLLMBrainOptions = {
  key: string
  ramMb: number
  // e.g. "Qwen3-0.6B-q4f16_1-MLC" (a model in WebLLM's prebuilt list).
  modelId: string
  // A worker that runs WebLLM's WebWorkerMLCEngineHandler (the app builds it).
  createWorker: () => Worker
  onProgress?: (fraction: number, text: string) => void
}

export class WebLLMBrain extends CompletionBrain {
  private engine: MLCEngineInterface | null = null
  private worker: Worker | null = null

  constructor(private config: WebLLMBrainOptions) {
    super({ model: config.key, tools: 'json' })
  }

  protected override async complete(request: CompletionRequest, signal?: AbortSignal): Promise<AsyncIterable<CompletionChunk>> {
    const engine = this.engine
    if (!engine) throw new Error('The model is not loaded.')
    signal?.addEventListener('abort', () => engine.interruptGenerate(), { once: true })
    const stream = await engine.chat.completions.create({
      messages: request.messages,
      stream: true,
      stream_options: { include_usage: true },
      max_tokens: 1024,
      temperature: 0.6,
      extra_body: { enable_thinking: false },
    })
    return stream as AsyncIterable<CompletionChunk>
  }

  get key(): string {
    return this.config.key
  }

  get ramMb(): number {
    return this.config.ramMb
  }

  async load(): Promise<void> {
    if (this.engine) return
    const webllm = await import('@mlc-ai/web-llm')
    this.worker = this.config.createWorker()
    try {
      this.engine = await webllm.CreateWebWorkerMLCEngine(this.worker, this.config.modelId, {
        initProgressCallback: report => this.config.onProgress?.(report.progress, report.text),
      })
    } catch (error) {
      this.worker.terminate()
      this.worker = null
      throw error
    }
  }

  async unload(): Promise<void> {
    await this.engine?.unload().catch(() => {})
    this.worker?.terminate()
    this.engine = null
    this.worker = null
  }
}

// Whether WebLLM already has this model's files in Cache Storage.
export async function webllmHasModel(modelId: string): Promise<boolean> {
  const webllm = await import('@mlc-ai/web-llm')
  return webllm.hasModelInCache(modelId)
}

export async function webllmDeleteModel(modelId: string): Promise<void> {
  const webllm = await import('@mlc-ai/web-llm')
  await webllm.deleteModelAllInfoInCache(modelId)
}
