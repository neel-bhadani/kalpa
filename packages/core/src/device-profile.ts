// What this device can do, measured in the browser. Plain TypeScript, no Vue.

export type DeviceTier = 'low' | 'mid' | 'high'

export type DeviceProfile = {
  // GB of RAM, rounded and capped at 8 by the browser. null where unsupported (Firefox, Safari).
  memoryGb: number | null
  // Logical CPU cores. null if unknown.
  cores: number | null
  webgl2: boolean
  // GPU renderer name from WebGL, e.g. "NVIDIA GeForce GT 710". null if hidden.
  gpu: string | null
  webgpu: boolean
  storageQuotaBytes: number | null
  storageUsageBytes: number | null
}

// Browser APIs that are missing from TypeScript's DOM types.
type ExtendedNavigator = Navigator & {
  deviceMemory?: number
  gpu?: { requestAdapter(): Promise<unknown | null> }
}

export async function collectDeviceProfile(): Promise<DeviceProfile> {
  const nav = navigator as ExtendedNavigator
  const { webgl2, gpu } = probeWebGl2()
  const storage = await nav.storage?.estimate?.().catch(() => undefined)

  return {
    memoryGb: nav.deviceMemory ?? null,
    cores: nav.hardwareConcurrency || null,
    webgl2,
    gpu,
    webgpu: await hasWebGpu(nav),
    storageQuotaBytes: storage?.quota ?? null,
    storageUsageBytes: storage?.usage ?? null,
  }
}

// low:  memory ≤ 4 GB, or cores ≤ 2, or no WebGL2
// high: memory ≥ 8 GB and cores ≥ 8 and WebGPU
// mid:  everything else
// Unknown memory or cores never make a device low, and keep it from being high.
// Browsers report deviceMemory as 8 at most, so "≥ 8" means "8".
export function computeTier(profile: DeviceProfile): DeviceTier {
  const { memoryGb, cores, webgl2, webgpu } = profile

  if (!webgl2 || (memoryGb !== null && memoryGb <= 4) || (cores !== null && cores <= 2)) {
    return 'low'
  }

  if (memoryGb !== null && memoryGb >= 8 && cores !== null && cores >= 8 && webgpu) {
    return 'high'
  }

  return 'mid'
}

function probeWebGl2(): { webgl2: boolean; gpu: string | null } {
  const gl = document.createElement('canvas').getContext('webgl2')
  if (!gl) {
    return { webgl2: false, gpu: null }
  }

  // Newer browsers expose the real name on RENDERER; older ones need the debug extension.
  const debug = gl.getExtension('WEBGL_debug_renderer_info')
  const gpu = String(gl.getParameter(debug ? debug.UNMASKED_RENDERER_WEBGL : gl.RENDERER) || '') || null

  // Free the context right away; browsers allow only a few at once.
  gl.getExtension('WEBGL_lose_context')?.loseContext()

  return { webgl2: true, gpu }
}

async function hasWebGpu(nav: ExtendedNavigator): Promise<boolean> {
  if (!nav.gpu) {
    return false
  }
  try {
    return (await nav.gpu.requestAdapter()) !== null
  } catch {
    return false
  }
}
