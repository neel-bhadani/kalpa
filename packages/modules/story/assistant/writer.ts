// The Story assistant: writes a story and its script the way a professional does, stage by stage
// (Brief → Logline → Characters → Outline → Scene list → Draft scenes → Polish → Review), and
// takes chat requests ("make scene 3 funnier"). Built for small models: each call is short and
// sees only what it needs (compact story, scene summaries, one scene), with a token cap.
//
// Every change is a step, made with the module's commands; a step that runs becomes its own asset
// version ("AI: Drafted Scene 4") carrying the step id, so Undo and Restore work per step. Nothing
// is written until a step's whole change is ready and valid. The plan is saved on the session
// (ai_sessions.plan), so Continue picks up after a stop, a reload or a crash.
//   auto   — every stage runs, steps apply at once
//   semi   — each stage stops with its steps waiting for approval
//   manual — each stage stops with its steps as suggestions

import { type Brain, type ChatMessage, type ToolCall, isAbortError } from '../../../ai-runtime/src/brain'
import { wireName } from '../../../ai-runtime/src/completion-brain'
import type { ModuleAssistant, ModuleAssistantEvent } from '../../../ai-runtime/src/module-assistant'
import type { AiApi, Mode, Step, StepStatus } from '../../../ai-runtime/src/runner'
import { validateArgs } from '../../../ai-runtime/src/validate'
import type { CommandCall } from '../diff'
import { isDirty, runCommand, summarize, undo as undoOps, type Draft } from '../draft'
import { characterName } from '../editor/convert'
import { applyPatch, get, type Operation } from '../patch'
import { estimate } from '../screenplay'
import { newId, type ScriptSpec, type SpecKind, type StoryCharacter, type StorySpec } from '../spec'
import { fill, PROMPTS, STAGES, target as targetFor, type Stage, type StageId, type Target } from './stages'
import { LANGUAGE_NAMES, linkSpeakers, parseDraft, parseEdits, parseLogline, sceneText, scriptShare, unknownSpeakers } from './text'
import { CHAT_TOOLS, getScene, listScenes, mapCall, memoryGet, READ_TOOLS, storyGet, TOOLS } from './tools'


// Where the drafts live and how versions are made (the task checkpoint and the API in the app,
// memory in tests and the eval).
export type WriterStore = {
  draft(kind: SpecKind): Promise<Draft<any> | null>
  // Show a draft in the editor (put it in the task checkpoint).
  save(kind: SpecKind, draft: Draft<any>): void
  // Commit the draft's queued changes as a version; returns the draft on top of it.
  commit(kind: SpecKind, draft: Draft<any>, message: string, stepId: string | null): Promise<Draft<any>>
  memory(): Promise<{ data: Record<string, any>; version: number }>
  patchMemory(ops: Operation[], baseVersion: number, stepId: string | null): Promise<void>
}

export type PlanState = 'running' | 'review' | 'waiting' | 'stopped' | 'done' | 'failed'

export type Plan = {
  brief: string
  minutes: number
  language: string
  // The stage to run next (or running), by index in STAGES.
  stage: number
  state: PlanState
  // Scenes the draft and polish stages already handled (proposed or applied).
  drafted: string[]
  polished: string[]
  answers: { question: string; answer: string }[]
  question: string | null
  // A chat request waiting for an answer.
  pendingChat?: string | null
}

// What a step does when it runs: commands on one asset, and story bible entries.
type StepArgs = {
  kind: SpecKind
  calls: CommandCall[]
  bible?: Record<string, string>
  // For a failed generation: what to try again.
  unit?: Unit
}

type Unit = { stage: StageId; sceneId?: string; instruction?: string } | { chat: string }

type Proposal = { title: string; reason: string; tool: string; args: StepArgs }

class Stopped extends Error {}

export type WriterOptions = {
  brain: Brain
  ai: AiApi
  store: WriterStore
  mode: () => Mode
  plan?: Partial<Plan> | null
  onEvent?: (event: ModuleAssistantEvent) => void
  // A short pause between Auto steps, so each can be seen happening (0 in tests and the eval).
  stepDelayMs?: number
}

export class StoryWriter implements ModuleAssistant {
  readonly stages = STAGES.map(({ id, label }) => ({ id, label }))
  plan: Plan | null
  private controller: AbortController | null = null

  constructor(private options: WriterOptions) {
    this.plan = options.plan && typeof options.plan.stage === 'number' ? { drafted: [], polished: [], answers: [], question: null, ...options.plan } as Plan : null
  }

  get busy(): boolean {
    return this.controller !== null
  }

  get canContinue(): boolean {
    return !this.busy && !!this.plan && ['running', 'review', 'stopped', 'failed'].includes(this.plan.state)
  }

  // ---- Entry points.

  async start(request: Record<string, unknown>): Promise<void> {
    const brief = String(request.brief ?? '').trim()
    if (!brief) throw new Error('Describe the story first.')
    const story = await this.need('story')
    const script = await this.options.store.draft('script')
    const language = String(request.language ?? script?.spec.language ?? story.spec.language ?? 'en')
    const minutes = Number(request.minutes) > 0 ? Number(request.minutes) : (story.spec.target_length ?? 5)
    await this.options.ai.addMessage('user', { text: `Write with AI: ${brief} (${minutes} min)` })
    this.plan = { brief, minutes, language, stage: 0, state: 'running', drafted: [], polished: [], answers: [], question: null }
    await this.savePlan()
    await this.run()
  }

  continue(): Promise<void> {
    if (!this.plan || this.busy) return Promise.resolve()
    if (this.plan.state === 'waiting') return Promise.resolve()
    this.plan.state = 'running'
    return this.run()
  }

  async answer(text: string): Promise<void> {
    if (!this.plan?.question) return
    await this.options.ai.addMessage('user', { text })
    this.plan.answers.push({ question: this.plan.question, answer: text })
    this.plan.question = null
    this.plan.state = 'running'
    this.emit({ type: 'question', text: null })
    await this.options.ai.updateSession?.({ status: 'active' })
    const chat = this.plan.pendingChat
    if (chat) {
      this.plan.pendingChat = null
      await this.savePlan()
      return this.send(`${chat}\n(Your question: ${this.plan.answers.at(-1)!.question} My answer: ${text})`, false)
    }
    await this.savePlan()
    return this.run()
  }

  abort(): void {
    this.controller?.abort()
  }

  // ---- The stage loop.

  private async run(): Promise<void> {
    const plan = this.plan!
    await this.guard(async (signal) => {
      // A Continue after the last stage only finishes up.
      while (plan.stage < STAGES.length) {
        const stage = STAGES[plan.stage]!
        this.emit({ type: 'stage', id: stage.id, state: 'running' })
        plan.state = 'running'
        await this.savePlan()

        const result = await this.runStage(stage, signal)
        if (result === 'waiting') return
        if (result === 'failed') {
          plan.state = 'failed'
          this.emit({ type: 'stage', id: stage.id, state: 'failed' })
          await this.savePlan()
          return
        }

        plan.stage++
        if (this.options.mode() !== 'auto' && result === 'proposed' && plan.stage < STAGES.length) {
          // Semi and Manual: stop for review after each stage that proposed something.
          plan.state = 'review'
          this.emit({ type: 'stage', id: stage.id, state: 'review' })
          await this.savePlan()
          return
        }
        this.emit({ type: 'stage', id: stage.id, state: 'done' })
      }
      plan.state = 'done'
      this.emit({ type: 'stage', id: null, state: 'done' })
      await this.savePlan()
      await this.options.ai.updateSession?.({ status: 'active' })
    })
  }

  // Runs a stage. 'proposed': it made steps; 'done': nothing to do; 'waiting': asked the user.
  private async runStage(stage: Stage, signal: AbortSignal): Promise<'proposed' | 'done' | 'waiting' | 'failed'> {
    switch (stage.id) {
      case 'logline': return this.logline(stage, signal)
      case 'draft': return this.draftAll(signal)
      case 'polish': return this.polishAll(signal)
      case 'review': return this.review(signal)
      default: return this.toolStage(stage, signal)
    }
  }

  // One call that uses the stage's tools: brief, logline, characters, outline, scene list.
  private async toolStage(stage: Stage, signal: AbortSignal, existing?: Step): Promise<'proposed' | 'done' | 'waiting' | 'failed'> {
    const plan = this.plan!
    const { story, script } = await this.specs()
    const goal = this.target()
    if (stage.done(story, script, goal) && !existing) return 'done'

    const prompt = fill(stage.prompt, { characters: goal.characters, acts: goal.acts, scenes: goal.scenes.ideal, minutes: goal.minutes })
    const context = [
      `The user's brief: ${plan.brief}`,
      `Target length: ${goal.minutes} minutes. Language: ${this.languageName()}.`,
      ...plan.answers.map(item => `You asked: ${item.question} The user answered: ${item.answer}`),
      `The story so far:\n${storyGet(story)}`,
      stage.id === 'scenes' ? `Scenes so far:\n${listScenes(script)}` : '',
      // Small models drift into writing the story itself; say plainly what to answer with.
      `Now answer only by calling ${stage.tools.filter(name => name !== 'ask_user').join(' / ')}. Don't write anything else.`,
    ].filter(Boolean).join('\n\n')

    const result = await this.callTools(prompt, context, stage.tools, stage.maxTokens, signal, story, script)
    if (result.question) return this.waitForUser(result.question)
    if (result.problems.length || !result.mapped.length) {
      await this.failedUnit(`Couldn't finish: ${stage.label}`, { stage: stage.id }, result.problems.join(' ') || 'The model made no changes.', existing)
      return 'failed'
    }
    if (existing) await this.options.ai.updateStep(existing.id, { status: 'done', after: { retried: true } })
    for (const proposal of result.mapped) await this.propose(proposal, signal)
    return 'proposed'
  }

  // The logline and synopsis, written as plain lines; one repair if a part is missing.
  private async logline(stage: Stage, signal: AbortSignal, existing?: Step): Promise<'proposed' | 'done' | 'failed'> {
    const { story } = await this.specs()
    if (stage.done(story, null, this.target()) && !existing) return 'done'
    const context = `The user's brief: ${this.plan!.brief}\n\nThe story so far:\n${storyGet(story)}`
    let parsed = parseLogline(await this.text(stage.prompt, context, stage.maxTokens, signal, 'Writing the logline'))
    if (!parsed.logline || !parsed.synopsis) {
      parsed = parseLogline(await this.text(stage.prompt, `${context}\n\nYour last answer was missing ${!parsed.logline ? 'LOGLINE' : 'SYNOPSIS'}. Answer again with REASON, LOGLINE and SYNOPSIS lines.`, stage.maxTokens, signal, 'Writing the logline'))
    }
    if (!parsed.logline || !parsed.synopsis) {
      await this.failedUnit('Couldn\'t finish: Logline', { stage: 'logline' }, 'The model\'s answer had no logline or no synopsis.', existing)
      return 'failed'
    }
    if (existing) await this.options.ai.updateStep(existing.id, { status: 'done', after: { retried: true } })
    await this.propose({
      title: 'Wrote the logline and synopsis',
      reason: parsed.reason || fallbackReason('Wrote the logline and synopsis'),
      tool: 'story.setLogline',
      args: { kind: 'story', calls: [{ name: 'story.setField', args: { field: 'logline', value: parsed.logline } }, { name: 'story.setField', args: { field: 'synopsis', value: parsed.synopsis } }] },
    }, signal)
    return 'proposed'
  }

  // Draft every scene that has no lines yet, one at a time.
  private async draftAll(signal: AbortSignal): Promise<'proposed' | 'done' | 'failed'> {
    const plan = this.plan!
    const { script } = await this.specs()
    if (!script) return 'done'
    const todo = script.scenes.filter(scene => !scene.lines.length && !plan.drafted.includes(scene.id))
    let proposed = false
    for (const scene of todo) {
      const { script: now } = await this.specs()
      const number = now!.scenes.findIndex(item => item.id === scene.id) + 1
      if (!number) continue
      this.emit({ type: 'progress', text: `Drafting scene ${number} of ${now!.scenes.length}` })
      const ok = await this.draftScene(scene.id, signal)
      if (!ok) return 'failed'
      plan.drafted.push(scene.id)
      await this.savePlan()
      proposed = true
    }
    this.emit({ type: 'progress', text: null })
    return proposed ? 'proposed' : 'done'
  }

  // Writes one scene (or rewrites it, following an instruction), checks the speakers, and
  // proposes it as one step. Returns false when the model's answer couldn't be used.
  private async draftScene(sceneId: string, signal: AbortSignal, instruction?: string, existing?: Step): Promise<boolean> {
    const { story, script } = await this.specs()
    const index = script!.scenes.findIndex(scene => scene.id === sceneId)
    const scene = script!.scenes[index]!
    const goal = this.target()
    const characters = this.cast(story)
    const previous = script!.scenes[index - 1]

    const prompt = fill(PROMPTS.draft, {
      number: index + 1, total: script!.scenes.length, lines: goal.linesPerScene, language: this.languageName(),
      cast: characters.map(character => character.name).join(', ') || 'anyone the scene needs',
      first: characters[0]?.name ?? 'MIRA',
      second: characters[1]?.name ?? characters[0]?.name ?? 'RAVI',
    })
    const castNames = characters.map(character => character.name)
    const context = [
      `The story:\n${storyGet(story)}`,
      `All scenes:\n${listScenes(script)}`,
      previous ? `The scene before (${index}): ${previous.summary ?? previous.heading}` : 'This is the first scene.',
      `Now write scene ${index + 1}: ${scene.heading}\nWhat happens: ${scene.summary ?? 'continue the story'}`,
      instruction ? `The user asks: ${instruction}\nThe scene now:\n${sceneText(scene.lines)}` : '',
    ].filter(Boolean).join('\n\n')

    const maxTokens = this.plan?.language && this.plan.language !== 'en' ? 1100 : 800
    let output = await this.text(prompt, context, maxTokens, signal, `Drafting scene ${index + 1}`)
    let draft = parseDraft(output, castNames)
    if (!draft.lines.length) {
      // One repair: the answer wasn't in the form asked for.
      output = await this.text(prompt, `${context}\n\nYour last answer had no ACTION or NAME lines. Write the scene again, every line as ACTION: … or NAME: …`, maxTokens, signal, `Drafting scene ${index + 1}`)
      draft = parseDraft(output, castNames)
    }
    if (!draft.lines.length || draft.lines.every(line => !line.text.trim())) {
      await this.failedUnit(`Couldn't draft Scene ${index + 1}`, { stage: 'draft', sceneId, instruction }, 'The model\'s answer had no scene lines.', existing)
      return false
    }
    // Too short for the target: ask once for the rest.
    if (!instruction && draft.lines.length < goal.linesPerScene * 0.6) {
      const more = parseDraft(await this.text(prompt, `${context}\n\nThe scene so far:\n${output}\n\nContinue the scene with about ${goal.linesPerScene - draft.lines.length} more lines in the same form. Don't repeat lines.`, maxTokens, signal, `Drafting scene ${index + 1}`), castNames)
      draft = { ...draft, lines: [...draft.lines, ...more.lines], speakers: [...new Set([...draft.speakers, ...more.speakers])] }
    }

    // Everyone who speaks must be a story character: new names are added in their own step first.
    const newcomers: StoryCharacter[] = []
    for (const name of unknownSpeakers(draft.speakers, characters)) {
      const character = { id: newId(), name, role: 'minor', description: `Speaks in Scene ${index + 1}.` }
      newcomers.push(character)
      await this.propose({
        title: `Added ${name}`,
        reason: `${name} speaks in Scene ${index + 1} but isn't in the story yet`,
        tool: 'story.addCharacter',
        args: { kind: 'story', calls: [{ name: 'story.addCharacter', args: character }] },
      }, signal)
    }

    const lines = linkSpeakers(draft.lines, [...characters, ...newcomers]).map(line => ({ id: newId(), ...line }))
    const summary = (draft.summary || scene.summary || '').slice(0, 2000)
    if (existing) await this.options.ai.updateStep(existing.id, { status: 'done', after: { retried: true } })
    await this.propose({
      title: instruction ? `Rewrote Scene ${index + 1}` : `Drafted Scene ${index + 1}`,
      reason: draft.reason || (instruction ? `Rewriting Scene ${index + 1}: ${instruction}` : `Scene ${index + 1}: ${summary}`),
      tool: 'script.draftScene',
      args: {
        kind: 'script',
        calls: [{ name: 'script.replaceLines', args: { scene_id: sceneId, lines } }, ...(summary ? [{ name: 'script.updateScene', args: { id: sceneId, summary } }] : [])],
        bible: summary ? { [sceneId]: summary } : undefined,
      },
    }, signal)
    return true
  }

  // Sharpen the dialogue of each drafted scene: a few line edits per scene.
  private async polishAll(signal: AbortSignal): Promise<'proposed' | 'done'> {
    const plan = this.plan!
    let proposed = false
    const { script } = await this.specs()
    for (const scene of script?.scenes ?? []) {
      if (plan.polished.includes(scene.id)) continue
      const { script: now } = await this.specs()
      const current = now?.scenes.find(item => item.id === scene.id)
      const number = (now?.scenes.indexOf(current!) ?? -1) + 1
      if (!current?.lines.some(line => line.type === 'dialogue')) {
        plan.polished.push(scene.id)
        continue
      }
      this.emit({ type: 'progress', text: `Polishing scene ${number} of ${now!.scenes.length}` })
      const output = await this.text(fill(PROMPTS.polish, { number }), `Scene ${number}: ${current.heading}\n${sceneText(current.lines)}`, 300, signal, `Polishing scene ${number}`)
      const { reason, edits } = parseEdits(output)
      const calls = edits
        .filter(edit => current.lines[edit.number - 1]?.type === 'dialogue' && edit.text.length <= 5000 && edit.text !== current.lines[edit.number - 1]!.text)
        .slice(0, 4)
        .map(edit => ({ name: 'script.updateLine', args: { id: current.lines[edit.number - 1]!.id, text: edit.text } }))
      if (calls.length) {
        await this.propose({ title: `Polished dialogue in Scene ${number}`, reason: reason || `Tightening the dialogue in Scene ${number}`, tool: 'script.polishScene', args: { kind: 'script', calls } }, signal)
        proposed = true
      }
      plan.polished.push(scene.id)
      await this.savePlan()
    }
    this.emit({ type: 'progress', text: null })
    return proposed ? 'proposed' : 'done'
  }

  // Code checks plus a short review from the model, as a message.
  private async review(signal: AbortSignal): Promise<'done'> {
    const { story, script } = await this.specs()
    const checks = checkScript(story, script, this.target(), this.plan!.language)
    const notes = checks.filter(check => !check.ok).map(check => `- ${check.label}: ${check.detail}`)
    const output = await this.text(PROMPTS.review, `${storyGet(story)}\n\nScenes:\n${listScenes(script)}\n\nChecks:\n${checks.map(check => `- ${check.label}: ${check.ok ? 'ok' : check.detail}`).join('\n')}`, 250, signal, 'Reviewing')
    const text = [output.replace(/<think>[\s\S]*?(<\/think>|$)/g, '').trim(), notes.length ? `Checks to look at:\n${notes.join('\n')}` : 'All checks passed.'].filter(Boolean).join('\n\n')
    await this.options.ai.addMessage('assistant', { text, tool_calls: [] })
    this.emit({ type: 'reply', text })
    return 'done'
  }

  // ---- Chat: "make scene 3 funnier", "add a scene where they meet".

  send(text: string, record = true): Promise<void> {
    return this.guard(async (signal) => {
      if (record) await this.options.ai.addMessage('user', { text })
      const { story, script } = await this.specs()
      const mentioned = [...text.matchAll(/\bscene\s+(\d{1,3})\b/gi)].map(match => Number(match[1]))
      const context = [
        `The story:\n${storyGet(story)}`,
        `Scenes:\n${listScenes(script)}`,
        ...mentioned.map(number => getScene(script, number)),
        `The user asks: ${text}`,
      ].join('\n\n')

      const result = await this.callTools(PROMPTS.chat, context, CHAT_TOOLS, 500, signal, story, script, true)
      if (result.question) {
        this.plan ??= { brief: '', minutes: this.target().minutes, language: this.language(story, script), stage: STAGES.length, state: 'done', drafted: [], polished: [], answers: [], question: null }
        this.plan.pendingChat = text
        await this.waitForUser(result.question)
        return
      }
      const reply = result.text.trim() || (result.problems.length ? 'I couldn\'t do that.' : 'Done.')
      await this.options.ai.addMessage('assistant', { text: reply, tool_calls: result.calls })
      this.emit({ type: 'reply', text: reply })
      if (result.problems.length) {
        await this.failedUnit('Couldn\'t do the request', { chat: text }, result.problems.join(' '))
        return
      }
      for (const proposal of result.mapped) await this.propose(proposal, signal)
      // Rewrites and new scenes are drafted by the scene writer.
      for (const call of result.calls) {
        if (call.name === 'script.rewriteScene') {
          const { script: now } = await this.specs()
          const scene = now?.scenes[Number(call.args.scene) - 1]
          if (scene) await this.draftScene(scene.id, signal, String(call.args.instruction))
        }
      }
      for (const proposal of result.mapped) {
        const added = proposal.args.calls.find(item => item.name === 'script.addScene')
        const { script: now } = await this.specs()
        if (added && now?.scenes.some(scene => scene.id === added.args.id)) await this.draftScene(String(added.args.id), signal)
      }
    })
  }

  // ---- Steps.

  // A proposal becomes a step; in Auto it runs at once.
  private async propose(proposal: Proposal, signal: AbortSignal): Promise<Step> {
    if (signal.aborted) throw new Stopped()
    const mode = this.options.mode()
    const status: StepStatus = mode === 'auto' ? 'running' : mode === 'semi' ? 'awaiting_review' : 'planned'
    const step = this.track(await this.options.ai.addStep({ title: proposal.title.slice(0, 250), tool: proposal.tool, args: proposal.args as unknown as Record<string, unknown>, status, reason: proposal.reason.slice(0, 300) }))
    if (status !== 'running') return step
    const done = await this.execute(step)
    if (this.options.stepDelayMs) await new Promise(resolve => setTimeout(resolve, this.options.stepDelayMs))
    return done
  }

  // Runs a step: its commands become one asset version carrying the step id, then its story bible
  // entries. If anything fails, nothing is kept.
  private async execute(step: Step): Promise<Step> {
    const args = step.args as unknown as StepArgs
    const { store, ai } = this.options
    try {
      const base = await this.clean(args.kind)
      let draft = { ...base, ops: [], changes: [] } as Draft<any>
      let inverse: Operation[] = []
      for (const call of args.calls) {
        const result = runCommand(draft, call.name, call.args)
        draft = result.draft
        inverse = [...result.inverse, ...inverse]
      }
      const forward = draft.ops
      // What the step's paths hold right after it: undo checks they're still the same.
      const tests = [...new Set(forward.filter(op => op.op !== 'remove').map(op => op.path))].map(path => ({ op: 'test' as const, path, value: get(draft.spec, splitPath(path)) }))
      const committed = await store.commit(args.kind, draft, `AI: ${step.title}`, step.id)
      store.save(args.kind, committed)

      let memoryInverse: Operation[] = []
      if (args.bible && Object.keys(args.bible).length) {
        const memory = await store.memory()
        const ops = bibleOps(memory.data, args.bible)
        memoryInverse = applyPatch(memory.data, ops).inverse
        await store.patchMemory(ops, memory.version, step.id)
      }

      return this.track(await ai.updateStep(step.id, {
        status: 'done',
        before: { inverse, memory: memoryInverse },
        after: { kind: args.kind, asset_id: committed.assetId, version: committed.baseVersion, tests },
      }))
    } catch (error) {
      if (isAbortError(error)) throw error
      return this.track(await ai.updateStep(step.id, { status: 'failed', error: messageOf(error) }))
    }
  }

  async approve(step: Step): Promise<Step> {
    return this.execute(this.track(await this.options.ai.approve(step.id)))
  }

  async reject(step: Step): Promise<Step> {
    return this.track(await this.options.ai.reject(step.id))
  }

  // Approve the stage's waiting steps in order, then go on.
  async approveStage(): Promise<void> {
    const pending = this.pending.filter(step => step.status === 'awaiting_review' || step.status === 'planned').sort((a, b) => a.seq - b.seq)
    for (const step of pending) await this.approve(step)
    if (this.plan && this.plan.state === 'review') await this.continue()
  }

  // Put back what an AI step changed, as a new version. Refused if those values changed since.
  async undo(step: Step): Promise<Step> {
    const after = step.after as { kind?: SpecKind; tests?: Operation[] } | null
    const before = step.before as { inverse?: Operation[]; memory?: Operation[] } | null
    if (step.status !== 'done' || !after?.kind || !before?.inverse) throw new Error('Only a finished step can be undone.')
    const { store } = this.options
    const draft = await this.clean(after.kind)
    try {
      applyPatch(draft.spec, after.tests ?? [])
    } catch {
      throw new Error('This was changed again after the step. Undo the later changes first.')
    }
    const reverted = undoOps({ ...draft, ops: [], changes: [] }, before.inverse)
    const committed = await store.commit(after.kind, reverted, `Undid AI: ${step.title}`, null)
    store.save(after.kind, committed)
    if (before.memory?.length) {
      const memory = await store.memory()
      await store.patchMemory(before.memory, memory.version, null).catch(() => {})
    }
    return this.track(await this.options.ai.undo(step.id))
  }

  // A failed step runs again: an apply that failed is applied again; a generation that failed is
  // generated again (its new steps follow).
  retry(step: Step): Promise<Step> {
    const args = step.args as unknown as StepArgs
    if (!args.unit) return this.options.ai.retry(step.id).then(running => this.execute(this.track(running)))

    let result = step
    return this.guard(async (signal) => {
      const running = this.track(await this.options.ai.retry(step.id))
      const unit = args.unit!
      if ('chat' in unit) {
        await this.options.ai.updateStep(running.id, { status: 'done', after: { retried: true } })
        await this.send(unit.chat, false)
      } else if (unit.stage === 'logline') {
        await this.logline(STAGES[1]!, signal, running)
      } else if (unit.stage === 'draft' && unit.sceneId) {
        await this.draftScene(unit.sceneId, signal, unit.instruction, running)
      } else {
        await this.toolStage(STAGES.find(stage => stage.id === unit.stage)!, signal, running)
      }
      result = running
    }).then(() => result)
  }

  // Steps this writer has seen, for "Approve stage".
  private pending: Step[] = []

  private track(step: Step): Step {
    this.pending = [...this.pending.filter(item => item.id !== step.id), step]
    this.emit({ type: 'step', step })
    return step
  }

  // Lets the panel hand over the session's steps after a reload.
  setSteps(steps: Step[]): void {
    this.pending = [...steps]
  }

  // A generation that couldn't be used: one failed step, with what to retry. Nothing written.
  private async failedUnit(title: string, unit: Unit, error: string, existing?: Step): Promise<void> {
    const { ai } = this.options
    const step = existing ?? this.track(await ai.addStep({ title, tool: 'assistant.generate', args: { kind: 'script', calls: [], unit } as unknown as Record<string, unknown>, status: 'running', reason: 'The model\'s answer could not be used.' }))
    this.track(await ai.updateStep(step.id, { status: 'failed', error: error.slice(0, 1900) }))
  }

  // ---- Calls to the model.

  // One call with tools, with read tools answered and one repair round for bad arguments.
  // Returns only fully valid, mapped proposals (or the problems, or a question).
  private async callTools(prompt: string, context: string, toolNames: string[], maxTokens: number, signal: AbortSignal, story: StorySpec, script: ScriptSpec | null, chat = false) {
    const tools = toolNames.map(name => TOOLS[name]!)
    const messages: ChatMessage[] = [{ role: 'system', content: toWire(`${this.system()}\n\n${prompt}`) }, { role: 'user', content: toWire(context) }]

    let repaired = false
    for (let round = 0; round < 4; round++) {
      const { text, calls } = await this.ask(messages, tools, maxTokens, signal)
      const question = calls.find(call => call.name === 'ask_user')
      if (question && typeof question.args.question === 'string' && question.args.question.trim()) {
        return { text, calls, mapped: [] as Proposal[], problems: [] as string[], question: question.args.question.trim() }
      }

      // Read tools (chat only): answer and ask again.
      const reads = calls.filter(call => READ_TOOLS.has(call.name))
      if (chat && reads.length && round < 2) {
        messages.push({ role: 'assistant', content: replay(text, reads) })
        const answers = await Promise.all(reads.map(async call => `${call.name} → ${await this.read(call, story, script)}`))
        messages.push({ role: 'user', content: answers.join('\n\n') })
        continue
      }

      const writes = calls.filter(call => !READ_TOOLS.has(call.name) && call.name !== 'ask_user')
      const problems: string[] = []
      // A stage must change something; a chat may just answer.
      if (!chat && !writes.length) problems.push(`You didn't call ${toolNames.filter(name => name !== 'ask_user').join(' or ')}. Call it now, with all its arguments.`)
      const mapped: Proposal[] = []
      // A dry run on copies: the calls must apply, one after another.
      let storyDraft = { assetId: '', kind: 'story', baseVersion: 0, spec: story, ops: [], changes: [], rev: 0 } as Draft<StorySpec>
      let scriptDraft = script ? { assetId: '', kind: 'script', baseVersion: 0, spec: script, ops: [], changes: [], rev: 0 } as Draft<ScriptSpec> : null
      for (const call of writes) {
        const spec = TOOLS[call.name]
        const errors = spec ? validateArgs(spec.parameters, call.args) : [`there is no "${call.name}" tool`]
        if (errors.length) {
          problems.push(`${call.name}: ${errors.join('; ')}.`)
          continue
        }
        // A rewrite is drafted afterwards by the scene writer; here only the scene must exist.
        if (call.name === 'script.rewriteScene') {
          if (!scriptDraft?.spec.scenes[Number(call.args.scene) - 1]) problems.push(`script.rewriteScene: there is no scene ${call.args.scene}.`)
          continue
        }
        try {
          const one = mapCall(call.name, call.args, storyDraft.spec, scriptDraft?.spec ?? null)
          for (const command of one.calls) {
            if (one.kind === 'story') storyDraft = runCommand(storyDraft, command.name, command.args).draft
            else scriptDraft = runCommand(scriptDraft!, command.name, command.args).draft
          }
          const reason = typeof call.args.reason === 'string' && call.args.reason.trim() ? call.args.reason.trim() : fallbackReason(one.title)
          mapped.push({ title: one.title, reason, tool: call.name, args: { kind: one.kind, calls: one.calls } })
        } catch (error) {
          problems.push(`${call.name}: ${messageOf(error)}`)
        }
      }
      if (!problems.length || repaired) {
        return { text, calls: writes, mapped: problems.length ? [] : mapped, problems, question: null as string | null }
      }
      // One repair: tell the model exactly what was wrong.
      repaired = true
      messages.push({ role: 'assistant', content: replay(text, writes) })
      messages.push({ role: 'user', content: toWire(`Problems with your tool calls:\n${problems.map(problem => `- ${problem}`).join('\n')}\nCall the tools again, all of them, corrected.`) })
    }
    return { text: '', calls: [] as ToolCall[], mapped: [] as Proposal[], problems: ['The model kept reading instead of answering.'], question: null as string | null }
  }

  // A call without tools: plain text (a drafted scene, edits, a review).
  private async text(prompt: string, context: string, maxTokens: number, signal: AbortSignal, label: string): Promise<string> {
    const { text } = await this.ask([{ role: 'system', content: `${this.system()}\n\n${prompt}` }, { role: 'user', content: context }], [], maxTokens, signal, label)
    return text
  }

  private async ask(messages: ChatMessage[], tools: typeof TOOLS[string][], maxTokens: number, signal: AbortSignal, label?: string): Promise<{ text: string; calls: ToolCall[] }> {
    let text = ''
    const calls: ToolCall[] = []
    for await (const chunk of this.options.brain.chat({ messages, tools, signal, maxTokens })) {
      if (signal.aborted) throw new Stopped()
      if (chunk.type === 'text') {
        text += chunk.text
        this.emit({ type: 'thinking', text: label ? `${label}…\n${text}` : text })
      } else if (chunk.type === 'tool_call') {
        calls.push(chunk.call)
      } else {
        this.emit(chunk)
      }
    }
    if (signal.aborted) throw new Stopped()
    this.emit({ type: 'thinking', text: '' })
    return { text, calls }
  }

  // ---- Helpers.

  // Runs work with a Stop button: a stop throws away what wasn't finished.
  private async guard(work: (signal: AbortSignal) => Promise<void>): Promise<void> {
    if (this.controller) throw new Error('The assistant is busy.')
    const controller = new AbortController()
    this.controller = controller
    try {
      await work(controller.signal)
    } catch (error) {
      if (error instanceof Stopped || isAbortError(error)) {
        if (this.plan && this.plan.state === 'running') this.plan.state = 'stopped'
        await this.savePlan().catch(() => {})
        this.emit({ type: 'stopped' })
        return
      }
      throw error
    } finally {
      this.controller = null
      this.emit({ type: 'thinking', text: '' })
      this.emit({ type: 'progress', text: null })
    }
  }

  private async waitForUser(question: string): Promise<'waiting'> {
    this.plan!.question = question
    this.plan!.state = 'waiting'
    await this.options.ai.addMessage('assistant', { text: question, tool_calls: [] })
    await this.options.ai.updateSession?.({ status: 'waiting_user', plan: this.plan! })
    this.emit({ type: 'question', text: question })
    this.emit({ type: 'plan', plan: this.plan! })
    return 'waiting'
  }

  private async savePlan(): Promise<void> {
    if (!this.plan) return
    this.emit({ type: 'plan', plan: this.plan })
    await this.options.ai.updateSession?.({ plan: this.plan })
  }

  // The draft with the person's unsaved edits committed first, so an AI step's version holds only its own change.
  private async clean(kind: SpecKind): Promise<Draft<any>> {
    const { store } = this.options
    let draft = await store.draft(kind)
    if (!draft) throw new Error(`This task has no ${kind}.`)
    if (isDirty(draft)) {
      draft = await store.commit(kind, draft, summarize(draft.changes), null)
      store.save(kind, draft)
    }
    return draft
  }

  private async need(kind: SpecKind): Promise<Draft<any>> {
    const draft = await this.options.store.draft(kind)
    if (!draft) throw new Error(`This task has no ${kind}.`)
    return draft
  }

  private async specs(): Promise<{ story: StorySpec; script: ScriptSpec | null }> {
    const story = (await this.need('story')).spec as StorySpec
    const script = ((await this.options.store.draft('script'))?.spec ?? null) as ScriptSpec | null
    return { story, script }
  }

  private cast(story: StorySpec): StoryCharacter[] {
    return story.characters ?? []
  }

  private target(): Target {
    return targetFor(this.plan?.minutes ?? 5)
  }

  private language(story: StorySpec, script: ScriptSpec | null): string {
    return this.plan?.language ?? script?.language ?? story.language ?? 'en'
  }

  private languageName(): string {
    const code = this.plan?.language ?? 'en'
    return LANGUAGE_NAMES[code] ?? code
  }

  private system(): string {
    return fill(PROMPTS.system, { language: this.languageName() })
  }

  private async read(call: ToolCall, story: StorySpec, script: ScriptSpec | null): Promise<string> {
    switch (call.name) {
      case 'story.get': return storyGet(story)
      case 'script.listScenes': return listScenes(script)
      case 'script.getScene': return getScene(script, Number(call.args.scene))
      default: return memoryGet((await this.options.store.memory()).data)
    }
  }

  private emit(event: ModuleAssistantEvent): void {
    this.options.onEvent?.(event)
  }
}

// ---- Story bible: one-line scene summaries in project memory, under /story_bible/scenes.

function bibleOps(memory: Record<string, any>, scenes: Record<string, string>): Operation[] {
  const ops: Operation[] = []
  if (!memory.story_bible || typeof memory.story_bible !== 'object') ops.push({ op: 'add', path: '/story_bible', value: {} })
  if (!memory.story_bible?.scenes || typeof memory.story_bible.scenes !== 'object') ops.push({ op: 'add', path: '/story_bible/scenes', value: {} })
  for (const [id, summary] of Object.entries(scenes)) ops.push({ op: 'add', path: `/story_bible/scenes/${id}`, value: summary })
  return ops
}

// ---- Checks (the Review stage, and the eval).

export type Check = { label: string; ok: boolean; detail: string }

export function checkScript(story: StorySpec, script: ScriptSpec | null, goal: Target, language: string): Check[] {
  const scenes = script?.scenes ?? []
  const known = new Set((story.characters ?? []).map(character => characterName(character.name)))
  let unknown = 0
  let speaker: string | null = null
  for (const scene of scenes) {
    for (const line of scene.lines) {
      if (line.type === 'character') speaker = characterName(line.text)
      else if (line.type === 'dialogue' && (!speaker || !known.has(speaker))) unknown++
      else if (line.type !== 'parenthetical') speaker = null
    }
  }
  const empty = scenes.filter(scene => !scene.heading.trim() || !scene.lines.some(line => line.text.trim())).length
  const runtime = estimate(scenes).minutes
  const text = scenes.flatMap(scene => scene.lines.filter(line => line.type === 'dialogue' || line.type === 'action').map(line => line.text)).join(' ')
  const share = scriptShare(text)
  const languageOk = language === 'hi' || language === 'mr' ? share.devanagari >= 0.6 : language === 'gu' ? share.gujarati >= 0.6 : language === 'en' ? share.latin >= 0.8 : true

  return [
    { label: 'Scene count', ok: scenes.length >= goal.scenes.min && scenes.length <= goal.scenes.max, detail: `${scenes.length} scenes (target ${goal.scenes.min}–${goal.scenes.max})` },
    { label: 'Known speakers', ok: unknown === 0, detail: unknown ? `${unknown} dialogue lines by unknown characters` : 'every speaker is a character' },
    { label: 'No empty scenes', ok: scenes.length > 0 && empty === 0, detail: empty ? `${empty} empty scenes` : 'all scenes written' },
    { label: 'Logline and synopsis', ok: Boolean(story.logline?.trim() && story.synopsis?.trim()), detail: story.logline ? 'present' : 'missing' },
    { label: 'Language', ok: languageOk, detail: `${Math.round(share.latin * 100)}% Latin, ${Math.round(share.devanagari * 100)}% Devanagari, ${Math.round(share.gujarati * 100)}% Gujarati letters` },
    { label: 'Runtime', ok: Math.abs(runtime - goal.minutes) <= goal.minutes * 0.3, detail: `about ${runtime.toFixed(1)} min (target ${goal.minutes} ±30%)` },
  ]
}

// Tool names in text for the model, in the form it must call them ("story.setBrief" → "story_setBrief").
const TOOL_NAMES = Object.keys(TOOLS).sort((a, b) => b.length - a.length)
function toWire(text: string): string {
  return TOOL_NAMES.reduce((result, name) => result.split(name).join(wireName(name)), text)
}

// The model's earlier answer, for a follow-up: its text and its calls in the form Qwen3 and other
// Hermes-style models are trained on (a model copies what it sees; any other shape it repeats is lost).
function replay(text: string, calls: ToolCall[]): string {
  const lines = calls.map(call => `<tool_call>\n${JSON.stringify({ name: wireName(call.name), arguments: call.args })}\n</tool_call>`)
  return [text.trim(), ...lines].filter(Boolean).join('\n')
}

// When the model gave no reason: a plain one from the step's title ("Adding act "The hunt"").
// Starts with "Step:" so the eval can count them.
export function fallbackReason(title: string): string {
  return `Step: ${title}`
}

function splitPath(path: string): string[] {
  return path.slice(1).split('/').map(part => part.replace(/~1/g, '/').replace(/~0/g, '~'))
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error)
}
