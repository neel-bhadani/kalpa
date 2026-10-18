// The language models this device can run, from the model registry (GET /api/v1/models), and how
// much memory each device tier may give them.

import type { DeviceTier } from '../device-profile'

// One engine's exact files for a model (model_registry.builds).
export type ModelBuilds = {
  // A GGUF file for wllama (llama.cpp in WASM, CPU).
  wllama?: { file: string; url: string; bytes: number; sha256?: string; ram_mb: number }
  // An MLC build for WebLLM (WebGPU); WebLLM downloads its shards itself.
  webllm?: { model_id: string; url: string; bytes: number; ram_mb: number }
  // The same model as an Ollama tag (for kalpa-ollama).
  ollama?: { tag: string; bytes: number }
}

export type CatalogModel = {
  key: string
  display_name: string
  capability: string
  min_tier: DeviceTier
  enabled: boolean
  builds: ModelBuilds
}

// RAM the loaded models may use together, and how many may be loaded at once.
// Low and mid devices keep one model at most (a phone or this 16 GB PC with other work open).
export const TIER_BUDGETS: Record<DeviceTier, { ramMb: number; maxLoaded: number }> = {
  low: { ramMb: 1200, maxLoaded: 1 },
  mid: { ramMb: 2048, maxLoaded: 1 },
  high: { ramMb: 4096, maxLoaded: 2 },
}

const TIER_RANK: Record<DeviceTier, number> = { low: 0, mid: 1, high: 2 }

export function tierAtLeast(tier: DeviceTier, min: DeviceTier): boolean {
  return TIER_RANK[tier] >= TIER_RANK[min]
}

// Enabled language models that have a build for this engine and fit this tier's budget,
// smallest first.
export function modelsThatFit(models: CatalogModel[], engine: 'wllama' | 'webllm', tier: DeviceTier): CatalogModel[] {
  return models
    .filter(model => model.capability === 'llm' && model.enabled && tierAtLeast(tier, model.min_tier))
    .filter(model => (model.builds[engine]?.ram_mb ?? Infinity) <= TIER_BUDGETS[tier].ramMb)
    .sort((a, b) => a.builds[engine]!.ram_mb - b.builds[engine]!.ram_mb)
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1e9) return `${(bytes / 1e9).toFixed(1)} GB`
  return `${Math.max(1, Math.round(bytes / 1e6))} MB`
}
