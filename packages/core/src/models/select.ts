// Which brain the assistant uses, from the device and the user's choice:
//   Auto:        WebGPU → WebLLM; else a provider if one is set up; else wllama (CPU) with the
//                smallest model that fits the tier's memory budget.
//   This device: the same, without providers.
//   A provider:  that provider (Local Ollama is one too).
//   Fake:        the scripted dev brain (local env only).

import type { DeviceTier } from '../device-profile'
import { modelsThatFit, type CatalogModel } from './catalog'

export type BrainChoice = 'auto' | 'device' | 'fake' | { provider: string }

// One of the user's providers (ai_provider_configs), as the picker needs it.
export type ProviderOption = { id: string; name: string; enabled: boolean; models: string[] }

export type BrainPlan =
  | { kind: 'webllm'; model: CatalogModel }
  | { kind: 'wllama'; model: CatalogModel }
  | { kind: 'provider'; provider: ProviderOption; model: string }
  | { kind: 'fake' }
  | { kind: 'none'; reason: string }

export type PickInput = {
  choice: BrainChoice
  tier: DeviceTier
  webgpu: boolean
  models: CatalogModel[]
  providers: ProviderOption[]
  devTools: boolean
}

export function pickBrain({ choice, tier, webgpu, models, providers, devTools }: PickInput): BrainPlan {
  if (choice === 'fake') {
    return devTools ? { kind: 'fake' } : { kind: 'none', reason: 'The test brain only exists in development.' }
  }

  if (typeof choice === 'object') {
    const provider = providers.find(item => item.id === choice.provider)
    if (!provider) return { kind: 'none', reason: 'That provider was removed.' }
    if (!provider.enabled || !provider.models[0]) return { kind: 'none', reason: `${provider.name} is switched off or lists no model.` }
    return { kind: 'provider', provider, model: provider.models[0] }
  }

  if (webgpu) {
    // The GPU is fast enough for the largest model that fits.
    const model = modelsThatFit(models, 'webllm', tier).at(-1)
    if (model) return { kind: 'webllm', model }
  }

  if (choice === 'auto') {
    const provider = providers.find(item => item.enabled && item.models[0])
    if (provider) return { kind: 'provider', provider, model: provider.models[0]! }
  }

  // On the CPU, the smallest model is the one that answers at a usable speed.
  const model = modelsThatFit(models, 'wllama', tier)[0]
  if (model) return { kind: 'wllama', model }

  return { kind: 'none', reason: 'No model fits this device\'s memory. Add a provider, or run Local Ollama.' }
}
