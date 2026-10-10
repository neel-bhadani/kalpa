<script setup lang="ts">
import { findModule } from '@kalpa/core'
import { AssistantRunner, FakeBrain, type AiApi, type CheckpointPort, type Mode, type Step } from '@kalpa/ai-runtime'

// The task's assistant: chat, a mode switch, and the steps it takes (approve, reject, undo, retry).
// The assistant runs in this browser (packages/ai-runtime); steps change the editor through the
// same commands its buttons use. Messages and steps also appear live on the user's other devices.
const props = defineProps<{ taskId: string; module: string; checkpoint: CheckpointPort }>()

type Message = { id: string; session_id: string; role: 'user' | 'assistant' | 'tool' | 'system'; content: { text?: string; tool_calls?: unknown[] }; created_at: string }
type Session = { id: string; assistant: string; mode: Mode; status: string; messages: Message[]; steps: Step[] }

const api = useApi()
const auth = useAuthStore()
const errors = useFormErrors()

const session = ref<Session | null>(null)
const input = ref('')
const thinking = ref<string | null>(null)
const stepError = ref('')
const canAct = computed(() => auth.currentWorkspace?.role !== 'viewer')
// No real models until P1: the scripted fake brain, in development only (APP_ENV=local).
const brain = auth.devTools ? new FakeBrain() : null

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
}

const runner = brain
  ? new AssistantRunner({
      brain,
      commands: findModule(props.module)?.commands ?? {},
      api: aiApi,
      checkpoint: props.checkpoint,
      mode: () => session.value?.mode ?? 'semi',
      // A short pause between Auto steps, so each one can be seen happening.
      stepDelayMs: 700,
      onEvent: (event) => {
        if (event.type === 'thinking') thinking.value = event.text
        if (event.type === 'reply' || event.type === 'stopped') thinking.value = null
        if (event.type === 'step') upsertStep(event.step)
      },
    })
  : null

async function load() {
  const { data: sessions } = await api<{ data: Session[] }>(`/api/v1/tasks/${props.taskId}/ai/sessions`)
  let current = sessions.find(item => item.status === 'active')
  if (!current && canAct.value) {
    current = (await api<{ data: Session }>(`/api/v1/tasks/${props.taskId}/ai/sessions`, { method: 'POST' })).data
  }
  if (!current) return
  session.value = (await api<{ data: Session }>(`/api/v1/ai/sessions/${current.id}`)).data
  runner?.setHistory(session.value.messages
    .filter(message => message.role === 'user' || message.role === 'assistant')
    .map(message => ({ role: message.role as 'user' | 'assistant', content: message.content.text ?? '' })))
}

async function send() {
  const text = input.value.trim()
  if (!text || !runner || runner.busy) return
  input.value = ''
  errors.clear()
  stepError.value = ''
  try {
    thinking.value = ''
    await runner.send(text)
  } catch (error) {
    thinking.value = null
    errors.show(error)
  }
}

async function setMode(mode: Mode) {
  if (!session.value || session.value.mode === mode) return
  session.value.mode = mode
  await api(`/api/v1/ai/sessions/${session.value.id}`, { method: 'PATCH', body: { mode } })
}

async function act(step: Step, action: 'approve' | 'reject' | 'undo' | 'retry') {
  if (!runner) return
  stepError.value = ''
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

onMounted(() => load().catch(error => errors.show(error)))
onBeforeUnmount(() => runner?.abort())
</script>

<template>
  <aside class="flex h-full flex-col rounded-xl bg-panel" data-test="assistant">
    <header class="flex items-center justify-between gap-2 border-b border-muted/15 px-4 py-3">
      <div>
        <p class="text-sm font-semibold text-accent">Assistant</p>
        <p class="text-xs capitalize text-muted">{{ session?.assistant ?? '…' }}<template v-if="!brain"> · models arrive in P1</template></p>
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
            <p v-if="step.error" class="mt-1 text-xs text-red-400">{{ step.error }}</p>
            <div v-if="canAct && runner" class="mt-2 flex gap-3 text-xs">
              <button v-if="['planned', 'awaiting_review'].includes(step.status)" class="text-accent hover:underline" data-test="approve" @click="act(step, 'approve')">Approve</button>
              <button v-if="['planned', 'awaiting_review'].includes(step.status)" class="text-muted hover:text-text" data-test="reject" @click="act(step, 'reject')">Reject</button>
              <button v-if="step.status === 'done'" class="text-muted hover:text-text" data-test="undo" @click="act(step, 'undo')">Undo</button>
              <button v-if="step.status === 'failed'" class="text-accent hover:underline" data-test="retry" @click="act(step, 'retry')">Retry</button>
            </div>
          </li>
        </ol>
      </section>

      <p v-if="errors.message.value" class="text-xs text-red-400">{{ errors.message.value }}</p>
    </div>

    <form v-if="canAct" class="flex gap-2 border-t border-muted/15 p-3" @submit.prevent="send">
      <input
        v-model="input"
        :disabled="!runner || thinking !== null"
        placeholder="Message the assistant"
        class="field !mt-0 flex-1"
        data-test="assistant-input"
      >
      <button v-if="thinking !== null" type="button" class="rounded-lg border border-muted/30 px-3 text-sm" @click="runner?.abort()">Stop</button>
      <button v-else :disabled="!runner || !input.trim()" class="rounded-lg bg-accent px-3 text-sm font-medium text-bg disabled:opacity-50" data-test="send">Send</button>
    </form>
  </aside>
</template>
