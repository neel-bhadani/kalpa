// WebLLM's engine runs here, off the page's thread (see WebLLMBrain in packages/ai-runtime).
import { WebWorkerMLCEngineHandler } from '@mlc-ai/web-llm'

const handler = new WebWorkerMLCEngineHandler()
self.onmessage = (message: MessageEvent) => handler.onmessage(message)
