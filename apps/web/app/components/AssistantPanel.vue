<script setup lang="ts">
import { findModule, formatBytes, type BrainChoice } from '@kalpa/core'
import { AssistantRunner, tokensPerSecond, type AiApi, type Brain, type CheckpointPort, type Mode, type ModuleAssistant, type ModuleAssistantEvent, type ModuleAssistantFactory, type StageState, type Step } from '@kalpa/ai-runtime'

// The task's assistant: chat, a mode switch, and the steps it takes (approve, reject, undo, retry).
// The assistant runs in this browser (packages/ai-runtime); steps change the editor through the
// same commands its buttons use. Messages and steps also appear live on the user's other devices.
type Output = { role: string; asset: { id: string; kind: string; current_version?: { number: number } } }
const props = defineProps<{ taskId: string; module: string; checkpoint: CheckpointPort; projectId: string; taskTitle: string; outputs: Output[] }>()
// The module's assistant is working (the editor locks itself meanwhile).
const emit = defineEmits<{ busy: [busy: boolean] }>()

type Message = { id: string; session_id: string; role: 'user' | 'assistant' | 'tool' | 'system'; content: { text?: string; tool_calls?: unknown[] }; created_at: string }
type Session = { id: string; assistant: string; mode: Mode; status: string; plan: Record<string, any> | null; messages: Message[]; steps: Step[] }

const api = useApi()
const auth = useAuthStore()
const errors = useFormErrors()

const session = ref<Session | null>(null)
const input = ref('')
const thinking = ref<string | null>(null)
const stepError = ref('')
const canAct = computed(() => auth.currentWorkspace?.role !== 'viewer')

// The brain: picked from the device and the user's choice (stores/brains.ts). A model that
// isn't on this device yet is downloaded only after the user says yes to its size.
const brains = useBrainsStore()
const missing = ref(0)
const brainError = ref('')
const current = computed<Brain | null>(() => brains.brainFor(brains.plan))
const status = computed(() => brains.describe(brains.plan))
const download = computed(() => (brains.plan.kind === 'wllama' ? brains.downloads[brains.plan.model.key] : undefined))
const ready = computed(() => current.value !== null && missing.value === 0)

watch(() => brains.plan, async (plan) => {
  brains.tokensPerSecond = null
  missing.value = plan.kind === 'wllama' || plan.kind === 'webllm' ? await brains.missingBytes(plan.kind, plan.model).catch(() => 0) : 0
}, { immediate: true })

// The picker's value: "auto", "device", "fake" or "provider:<id>".
const choiceValue = computed({
  get: () => (typeof brains.choice === 'object' ? `provider:${brains.choice.provider}` : brains.choice),
  set: (value: string) => brains.setChoice((value.startsWith('provider:') ? { provider: value.slice(9) } : value) as BrainChoice),
})

async function downloadModel() {
  if (brains.plan.kind !== 'wllama') {
    // WebLLM fetches its files while loading; the first message starts it.
    missing.value = 0
    return
  }
  brainError.value = ''
  try {
    await brains.download(brains.plan.model)
    missing.value = 0
  } catch {
    // Shown from brains.downloads.
  }
}

// The runner asks whichever brain is picked right now.
const brain: Brain = {
  chat: request => current.value!.chat(request),
}

const steps = computed(() => [...(session.value?.steps ?? [])].sort((a, b) => a.seq - b.seq))

function upsertStep(step: Step) {
  if (!session.value) return
  const others = session.value.steps.filter(item => item.id !== step.id)
  session.value.steps = [...others, step]
}

function addMessage(message: Message) {
  if (session.value && !session.value.messages.some(item => item.id === message.id)) {
    session.value.messages = [...session.value.messages, message]
  }
}

const aiApi: AiApi = {
  addMessage: async (role, content) => {
    const { data } = await api<{ data: Message }>(`/api/v1/ai/sessions/${session.value!.id}/messages`, { method: 'POST', body: { role, content } })
    addMessage(data)
    return data
  },
  addStep: async step => (await api<{ data: Step }>(`/api/v1/ai/sessions/${session.value!.id}/steps`, { method: 'POST', body: step })).data,
  updateStep: async (id, patch) => (await api<{ data: Step }>(`/api/v1/ai/steps/${id}`, { method: 'PATCH', body: patch })).data,
  approve: async id => (await api<{ data: Step }>(`/api/v1/ai/steps/${id}/approve`, { method: 'POST' })).data,
  reject: async id => (await api<{ data: Step }>(`/api/v1/ai/steps/${id}/reject`, { method: 'POST' })).data,
  undo: async id => (await api<{ data: Step }>(`/api/v1/ai/steps/${id}/undo`, { method: 'POST' })).data,
  retry: async id => (await api<{ data: Step }>(`/api/v1/ai/steps/${id}/retry`, { method: 'POST' })).data,
  updateSession: async (patch) => {
    const { data } = await api<{ data: Session }>(`/api/v1/ai/sessions/${session.value!.id}`, { method: 'PATCH', body: patch })
    if (session.value) Object.assign(session.value, { status: data.status, plan: data.plan })
  },
}

// ---- The module's own assistant (e.g. the Story writer), when the module has one.

const helper = shallowRef<ModuleAssistant | null>(null)
const helperBusy = ref(false)
const stageStates = ref<Record<string, StageState>>({})
const progress = ref<string | null>(null)
const question = ref<string | null>(null)
const answerText = ref('')
const plan = computed(() => session.value?.plan ?? null)

let fakeHelperBrain: Brain | null = null
// The module's scripted brain stands in for the test brain.
const helperBrain: Brain = {
  chat: request => (brains.plan.kind === 'fake' && fakeHelperBrain ? fakeHelperBrain : current.value!).chat(request),
}

async function loadHelper() {
  const loader = findModule(props.module)?.assistant
  if (!loader || !session.value || helper.value) return
  const factory = (await loader()).default as ModuleAssistantFactory
  fakeHelperBrain = factory.fakeBrain?.() ?? null
  const saved = session.value.plan && Object.keys(session.value.plan).length ? session.value.plan : null
  helper.value = factory.createAssistant({
    brain: helperBrain,
    ai: aiApi,
    api: api as never,
    checkpoint: props.checkpoint,
    task: { id: props.taskId, projectId: props.projectId, title: props.taskTitle, outputs: props.outputs },
    mode: () => session.value?.mode ?? 'semi',
    plan: saved,
    onEvent: onHelperEvent,
  })
  ;(helper.value as { setSteps?: (steps: Step[]) => void }).setSteps?.(session.value.steps)
  question.value = saved?.state === 'waiting' ? saved.question ?? null : null
}

function onHelperEvent(event: ModuleAssistantEvent) {
  switch (event.type) {
    case 'thinking': thinking.value = event.text ? event.text : (helperBusy.value ? '' : null); break
    case 'reply': break
    case 'progress': progress.value = event.text; break
    case 'stage': if (event.id) stageStates.value = { ...stageStates.value, [event.id]: event.state }; break
    case 'step': upsertStep(event.step); break
    case 'question': question.value = event.text; break
    case 'plan': if (session.value) session.value.plan = { ...event.plan }; break
    case 'usage': brains.tokensPerSecond = tokensPerSecond(event); break
    case 'stopped': thinking.value = null; break
  }
}

// Runs one of the helper's jobs, with the busy flag, and errors shown in the panel.
async function helperJob(work: (helper: ModuleAssistant) => Promise<unknown>) {
  if (!helper.value || helperBusy.value) return
  brainError.value = ''
  stepError.value = ''
  helperBusy.value = true
  thinking.value = ''
  emit('busy', true)
  // Let the editor see it and save what it has into the checkpoint before the assistant reads it.
  await nextTick()
  await nextTick()
  try {
    await work(helper.value)
  } catch (error) {
    if (error instanceof Error && !('status' in error)) brainError.value = error.message
    else errors.show(error)
  } finally {
    helperBusy.value = false
    thinking.value = null
    progress.value = null
    emit('busy', false)
    // Reload what the server has (e.g. messages the helper added).
    await refresh().catch(() => {})
  }
}

// From the editor's "Write with AI" box: { brief, minutes, mode, language }.
async function startWriting(request: Record<string, unknown>) {
  if (!ready.value) {
    brainError.value = 'Pick a brain that is ready first (download the model, or choose a provider).'
    return
  }
  if (request.mode) await setMode(request.mode as Mode)
  stageStates.value = {}
  await helperJob(h => h.start(request))
}

function sendAnswer() {
  const text = answerText.value.trim()
  if (!text) return
  answerText.value = ''
  void helperJob(h => h.answer(text))
}

// A stage's state in the bar: from the plan (stages before plan.stage are done), refined by events.
function stageState(id: string, index: number): StageState {
  if (stageStates.value[id]) return stageStates.value[id]!
  const at = plan.value?.stage as number | undefined
  if (at === undefined) return 'waiting'
  if (index < at) return 'done'
  if (index === at && plan.value?.state === 'failed') return 'failed'
  return 'waiting'
}

const canContinue = computed(() => Boolean(helper.value && !helperBusy.value && plan.value && ['running', 'review', 'stopped', 'failed'].includes(plan.value.state)))
const waitingSteps = computed(() => steps.value.filter(step => step.status === 'awaiting_review' || step.status === 'planned').length)

defineExpose({ startWriting })

const runner = new AssistantRunner({
  brain,
  commands: findModule(props.module)?.commands ?? {},
  api: aiApi,
  checkpoint: props.checkpoint,
  mode: () => session.value?.mode ?? 'semi',
  system: `You are the ${props.module} assistant in Kalpa Studio, helping with one task. Reply briefly. When the user asks for a change, make it with the tools.`,
  // A short pause between Auto steps, so each one can be seen happening.
  stepDelayMs: 700,
  onEvent: (event) => {
    if (event.type === 'thinking') thinking.value = event.text
    if (event.type === 'reply' || event.type === 'stopped') thinking.value = null
    if (event.type === 'step') upsertStep(event.step)
    if (event.type === 'usage') brains.tokensPerSecond = tokensPerSecond(event)
  },
})

async function refresh() {
  if (!session.value) return
  const { data } = await api<{ data: Session }>(`/api/v1/ai/sessions/${session.value.id}`)
  session.value = { ...data, steps: data.steps.length >= session.value.steps.length ? data.steps : session.value.steps }
}

async function load() {
  const { data: sessions } = await api<{ data: Session[] }>(`/api/v1/tasks/${props.taskId}/ai/sessions`)
  let current = sessions.find(item => item.status === 'active')
  if (!current && canAct.value) {
    current = (await api<{ data: Session }>(`/api/v1/tasks/${props.taskId}/ai/sessions`, { method: 'POST' })).data
  }
  if (!current) return
  session.value = (await api<{ data: Session }>(`/api/v1/ai/sessions/${current.id}`)).data
  runner.setHistory(session.value.messages
    .filter(message => message.role === 'user' || message.role === 'assistant')
    .map(message => ({ role: message.role as 'user' | 'assistant', content: message.content.text ?? '' })))
}

async function send() {
  const text = input.value.trim()
  if (!text || !ready.value || runner.busy || helperBusy.value) return
  if (helper.value) {
    input.value = ''
    await helperJob(h => h.send(text))
    return
  }
  input.value = ''
  errors.clear()
  stepError.value = ''
  brainError.value = ''
  try {
    thinking.value = ''
    await runner.send(text)
  } catch (error) {
    thinking.value = null
    // Saving the message failed (an API error), or the brain did (a plain Error).
    if (error instanceof Error && !('status' in error)) brainError.value = error.message
    else errors.show(error)
  }
}

async function setMode(mode: Mode) {
  if (!session.value || session.value.mode === mode) return
  session.value.mode = mode
  await api(`/api/v1/ai/sessions/${session.value.id}`, { method: 'PATCH', body: { mode } })
}

async function act(step: Step, action: 'approve' | 'reject' | 'undo' | 'retry') {
  stepError.value = ''
  if (helper.value) {
    if (action === 'retry') return helperJob(h => h.retry(step))
    try {
      helperBusy.value = true
      emit('busy', true)
      await helper.value[action](step)
    } catch (error) {
      stepError.value = error instanceof Error ? error.message : 'That did not work.'
    } finally {
      helperBusy.value = false
      emit('busy', false)
    }
    return
  }
  try {
    await runner[action](step)
  } catch (error) {
    stepError.value = error instanceof Error ? error.message : 'That did not work.'
  }
}

// Live from the user's other devices (and this one: duplicates are dropped by id).
useLiveEvent(() => `task.${props.taskId}`, 'AiMessageCreated', (message: Message) => {
  if (message && (!session.value || message.session_id === session.value.id)) addMessage(message)
})
useLiveEvent(() => `task.${props.taskId}`, 'AiStepUpdated', (step: Step & { session_id: string }) => {
  if (session.value && step.session_id === session.value.id) upsertStep(step)
})

// The session's mode or status changed on another device (it arrives through sync).
const sync = useSyncStore()
watch(() => sync.lastChanges, (changes) => {
  const change = changes.find(item => item.entity === 'ai_session' && item.entity_id === session.value?.id)
  if (change?.payload && session.value) {
    session.value.mode = change.payload.mode
    session.value.status = change.payload.status
  }
})

const statusClasses: Record<Step['status'], string> = {
  planned: 'bg-muted/15 text-muted',
  awaiting_review: 'bg-amber-400/15 text-amber-300',
  running: 'bg-media/15 text-media animate-pulse',
  done: 'bg-green-500/15 text-green-400',
  rejected: 'bg-muted/15 text-muted line-through',
  undone: 'bg-muted/15 text-muted',
  failed: 'bg-red-500/15 text-red-400',
}

const modes: { key: Mode; label: string; hint: string }[] = [
  { key: 'auto', label: 'Auto', hint: 'Steps run at once' },
  { key: 'semi', label: 'Semi', hint: 'You approve each step' },
  { key: 'manual', label: 'Manual', hint: 'Suggestions only' },
]

onMounted(() => {
  load().then(loadHelper).catch(error => errors.show(error))
  brains.load().catch(() => { brainError.value = 'Could not load the model list.' })
})
onBeforeUnmount(() => {
  runner.abort()
  helper.value?.abort()
})

function stop() {
  runner.abort()
  helper.value?.abort()
}
</script>

<template>
  <aside class="flex h-full flex-col rounded-xl bg-panel" data-test="assistant">
    <header class="flex items-center justify-between gap-2 border-b border-muted/15 px-4 py-3">
      <div>
        <p class="text-sm font-semibold text-accent">Assistant</p>
        <p class="text-xs capitalize text-muted">{{ session?.assistant ?? '…' }}</p>
      </div>
      <div class="flex rounded-lg border border-muted/25 p-0.5 text-xs" role="radiogroup" aria-label="Assistant mode">
        <button
          v-for="mode in modes"
          :key="mode.key"
          role="radio"
          :aria-checked="session?.mode === mode.key"
          :title="mode.hint"
          :disabled="!canAct"
          class="rounded-md px-2 py-1"
          :class="session?.mode === mode.key ? 'bg-accent text-bg' : 'text-muted hover:text-text'"
          :data-test="`mode-${mode.key}`"
          @click="setMode(mode.key)"
        >
          {{ mode.label }}
        </button>
      </div>
    </header>

    <div class="flex items-center gap-2 border-b border-muted/15 px-4 py-2 text-xs">
      <select v-model="choiceValue" class="rounded-md border border-muted/25 bg-bg px-1.5 py-1 text-xs" aria-label="Brain" data-test="brain-picker">
        <option value="auto">Auto</option>
        <option value="device">This device</option>
        <option v-for="provider in brains.providers.filter(item => item.enabled)" :key="provider.id" :value="`provider:${provider.id}`">{{ provider.name }}</option>
        <option v-if="auth.devTools" value="fake">Test brain (dev)</option>
      </select>
      <p class="min-w-0 flex-1 truncate font-mono text-muted" data-test="brain-status">
        <template v-if="status">{{ status.name }} · {{ status.where }}<template v-if="brains.tokensPerSecond"> · {{ brains.tokensPerSecond.toFixed(1) }} tok/s</template></template>
        <template v-else-if="brains.plan.kind === 'none'">{{ brains.plan.reason }}</template>
      </p>
    </div>

    <div v-if="missing > 0 && (brains.plan.kind === 'wllama' || brains.plan.kind === 'webllm')" class="border-b border-muted/15 px-4 py-3 text-xs" data-test="download-offer">
      <template v-if="download && !download.error">
        <p>{{ download.checking ? 'Checking the file…' : `Downloading ${brains.plan.model.display_name}…` }}</p>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-bg">
          <div class="h-full bg-media" :style="{ width: `${(download.done / download.total) * 100}%` }" />
        </div>
        <p class="mt-1 flex justify-between font-mono text-muted">
          <span>{{ formatBytes(download.done) }} / {{ formatBytes(download.total) }}</span>
          <button v-if="!download.checking" class="text-muted hover:text-text" @click="brains.stopDownload(brains.plan.model)">Pause</button>
        </p>
      </template>
      <template v-else>
        <p>
          {{ brains.plan.model.display_name }} runs on this device after a one-time download of
          <span class="font-mono">{{ formatBytes(missing) }}</span>. It stays saved in this browser.
        </p>
        <p v-if="download?.error" class="mt-1 text-red-400">{{ download.error }}</p>
        <button class="mt-2 rounded-md bg-accent px-2 py-1 font-medium text-bg" data-test="download-model" @click="downloadModel">
          Download {{ formatBytes(missing) }}
        </button>
      </template>
    </div>
    <p v-if="brains.gpuProgress" class="border-b border-muted/15 px-4 py-2 text-xs text-muted">{{ brains.gpuProgress.text }}</p>

    <section v-if="helper && plan && plan.stage !== undefined" class="border-b border-muted/15 px-4 py-2" data-test="stage-bar">
      <ol class="flex flex-wrap gap-1 text-[10px]">
        <li
          v-for="(stage, i) in helper.stages"
          :key="stage.id"
          class="rounded-full px-2 py-0.5"
          :class="{
            'bg-green-500/15 text-green-400': stageState(stage.id, i) === 'done',
            'bg-media/15 text-media animate-pulse': stageState(stage.id, i) === 'running',
            'bg-amber-400/15 text-amber-300': stageState(stage.id, i) === 'review',
            'bg-red-500/15 text-red-400': stageState(stage.id, i) === 'failed',
            'bg-muted/10 text-muted': stageState(stage.id, i) === 'waiting',
          }"
          :data-state="stageState(stage.id, i)"
          data-test="stage"
        >
          {{ stage.label }}
        </li>
      </ol>
      <p v-if="progress" class="mt-1 font-mono text-xs text-media" data-test="progress">{{ progress }}</p>
      <div v-if="canAct" class="mt-2 flex flex-wrap gap-2 text-xs">
        <button v-if="plan.state === 'review' && waitingSteps && !helperBusy" class="rounded-md bg-accent px-2 py-1 font-medium text-bg" data-test="approve-stage" @click="helperJob(h => h.approveStage())">
          Approve stage ({{ waitingSteps }})
        </button>
        <button v-if="canContinue" class="rounded-md border border-muted/30 px-2 py-1 hover:border-accent hover:text-accent" data-test="continue" @click="helperJob(h => h.continue())">
          Continue
        </button>
        <span v-if="plan.state === 'done'" class="text-green-400">Done</span>
      </div>
    </section>

    <form v-if="question && canAct" class="border-b border-muted/15 px-4 py-3 text-sm" data-test="question" @submit.prevent="sendAnswer">
      <p class="text-accent">{{ question }}</p>
      <div class="mt-2 flex gap-2">
        <input v-model="answerText" class="field !mt-0 flex-1" placeholder="Your answer" data-test="answer-input">
        <button class="rounded-lg bg-accent px-3 text-sm font-medium text-bg" data-test="answer">Answer</button>
      </div>
    </form>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 text-sm" data-test="chat">
      <p v-if="!session?.messages.length && thinking === null" class="text-muted">Ask the assistant for help. Try "demo".</p>
      <div v-for="message in session?.messages ?? []" :key="message.id" :class="message.role === 'user' ? 'text-right' : ''">
        <p
          class="inline-block max-w-[90%] rounded-lg px-3 py-2 text-left"
          :class="message.role === 'user' ? 'bg-accent/15' : 'bg-bg'"
        >
          {{ message.content.text }}
        </p>
      </div>
      <div v-if="thinking !== null" data-test="thinking">
        <p class="text-xs text-accent">Assistant is thinking…</p>
        <p v-if="thinking" class="mt-1 inline-block max-w-[90%] rounded-lg bg-bg px-3 py-2">{{ thinking }}</p>
      </div>

      <section v-if="steps.length" class="border-t border-muted/15 pt-3">
        <h3 class="text-xs text-muted">Steps</h3>
        <p v-if="stepError" class="mt-2 text-xs text-red-400">{{ stepError }}</p>
        <ol class="mt-2 space-y-2">
          <li v-for="step in steps" :key="step.id" class="rounded-lg bg-bg p-2" data-test="step">
            <div class="flex items-start gap-2">
              <span class="font-mono text-xs text-muted">{{ step.seq }}</span>
              <span class="min-w-0 flex-1 text-xs">{{ step.title }}</span>
              <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px]" :class="statusClasses[step.status]" data-test="step-status">
                {{ step.status.replace('_', ' ') }}
              </span>
            </div>
            <p v-if="step.reason" class="mt-1 text-xs italic text-muted" data-test="step-reason">{{ step.reason }}</p>
            <p v-if="step.error" class="mt-1 text-xs text-red-400">{{ step.error }}</p>
            <div v-if="canAct" class="mt-2 flex gap-3 text-xs">
              <button v-if="['planned', 'awaiting_review'].includes(step.status)" class="text-accent hover:underline" data-test="approve" @click="act(step, 'approve')">Approve</button>
              <button v-if="['planned', 'awaiting_review'].includes(step.status)" class="text-muted hover:text-text" data-test="reject" @click="act(step, 'reject')">Reject</button>
              <button v-if="step.status === 'done'" class="text-muted hover:text-text" data-test="undo" @click="act(step, 'undo')">Undo</button>
              <button v-if="step.status === 'failed'" class="text-accent hover:underline" data-test="retry" @click="act(step, 'retry')">Retry</button>
            </div>
          </li>
        </ol>
      </section>

      <p v-if="errors.message.value" class="text-xs text-red-400">{{ errors.message.value }}</p>
      <p v-if="brainError" class="text-xs text-red-400" data-test="brain-error">{{ brainError }}</p>
    </div>

    <form v-if="canAct" class="flex gap-2 border-t border-muted/15 p-3" @submit.prevent="send">
      <input
        v-model="input"
        :disabled="!ready || thinking !== null || helperBusy || !!question"
        placeholder="Message the assistant"
        class="field !mt-0 flex-1"
        data-test="assistant-input"
      >
      <button v-if="thinking !== null || helperBusy" type="button" class="rounded-lg border border-muted/30 px-3 text-sm" data-test="stop" @click="stop">Stop</button>
      <button v-else :disabled="!ready || !input.trim()" class="rounded-lg bg-accent px-3 text-sm font-medium text-bg disabled:opacity-50" data-test="send">Send</button>
    </form>
  </aside>
</template>
