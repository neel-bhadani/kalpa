// A brain on this device's CPU: wllama (llama.cpp compiled to WebAssembly) runs a GGUF model in a
// Web Worker of its own. Works in any modern browser, no GPU needed — the fallback that always works.
// The GGUF file comes from the model manager's store (downloaded once); load() and unload() are
// called by the model manager, which keeps the device inside its memory budget.

// The package's built files (its root entry is TypeScript source).
import type { ChatCompletionParams, Wllama } from '@wllama/wllama/esm/index.js'
import { CompletionBrain, type CompletionChunk, type CompletionRequest, type ToolMode } from './completion-brain'

export type WllamaBrainOptions = {
  // model_registry key, e.g. "qwen3-0.6b", and the RAM it needs once loaded.
  key: string
  ramMb: number
  // URL of wllama.wasm, served by the app.
  wasmUrl: string
  // The downloaded GGUF file.
  file: () => Promise<Blob>
  contextSize?: number
  tools?: ToolMode
}

export class WllamaBrain extends CompletionBrain {
  private wllama: Wllama | null = null

  constructor(private config: WllamaBrainOptions) {
    super({ model: config.key, tools: config.tools ?? 'native' })
  }

  protected override async complete(request: CompletionRequest, signal?: AbortSignal): Promise<AsyncIterable<CompletionChunk>> {
    if (!this.wllama) throw new Error('The model is not loaded.')
    const stream = await this.wllama.createChatCompletion({
      messages: request.messages as ChatCompletionParams['messages'],
      tools: request.tools as ChatCompletionParams['tools'],
      stream: true,
      abortSignal: signal,
      max_tokens: request.maxTokens ?? 1024,
      temperature: 0.6,
    })
    return stream as AsyncIterable<CompletionChunk>
  }

  get key(): string {
    return this.config.key
  }

  get ramMb(): number {
    return this.config.ramMb
  }

  get loaded(): boolean {
    return this.wllama !== null
  }

  // Threads in use (1 unless the page is cross-origin isolated).
  get threads(): number {
    return this.wllama?.getNumThreads() ?? 0
  }

  async load(): Promise<void> {
    if (this.wllama) return
    const { Wllama } = await import('@wllama/wllama/esm/index.js')
    const wllama = new Wllama({ default: this.config.wasmUrl }, { suppressNativeLog: true, logger: { ...console, debug: () => {}, log: () => {} } })
    try {
      await wllama.loadModel([await this.config.file()], {
        n_ctx: this.config.contextSize ?? 4096,
        // CPU only: devices with WebGPU use WebLLM instead.
        n_gpu_layers: 0,
        n_parallel: 1,
        jinja: true,
        default_template_kwargs: { enable_thinking: false },
      })
    } catch (error) {
      await wllama.exit().catch(() => {})
      throw error
    }
    this.wllama = wllama
  }

  async unload(): Promise<void> {
    const wllama = this.wllama
    this.wllama = null
    await wllama?.exit()
  }
}
