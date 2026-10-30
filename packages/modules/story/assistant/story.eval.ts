// make eval-story: writes every brief in e2e/evals/story-briefs.json in Auto mode with a real
// model (kalpa-ollama), checks the result, and saves it to e2e/evals/results/<model>.json for the
// report (php artisan eval:story-report). Not part of make test.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { it } from 'vitest'
import type { Brain } from '../../../ai-runtime/src/brain'
import { directPost, OpenAICompatibleBrain } from '../../../ai-runtime/src/openai-compatible-brain'
import { newId, type ScriptSpec, type StorySpec } from '../spec'
import { estimate } from '../screenplay'
import { memoryAi, memoryStore } from './memory-store'
import { target } from './stages'
import { checkScript, StoryWriter } from './writer'

type Brief = { id: string; genre: string; minutes: number; language: string; brief: string }

const DIR = process.env.EVAL_DIR ?? '/kalpa/e2e/evals'
const MODEL = process.env.EVAL_MODEL ?? 'qwen3:1.7b'
const URL = process.env.EVAL_URL ?? 'http://ollama:11434/v1'
const ONLY = process.env.EVAL_ONLY ?? ''

it(`writes every brief with ${MODEL}`, async () => {
  const briefs = (JSON.parse(readFileSync(`${DIR}/story-briefs.json`, 'utf8')) as Brief[]).filter(brief => !ONLY || brief.id === ONLY)
  const results = []

  for (const brief of briefs) {
    const story: StorySpec = { title: brief.id, language: brief.language, target_length: brief.minutes, acts: [], characters: [], themes: [] }
    const script: ScriptSpec = { title: brief.id, format: brief.genre === 'advertisement' ? 'ad' : 'screenplay', language: brief.language, scenes: [{ id: newId(), heading: '', lines: [] }] }
    const mem = memoryStore(story, script)
    const ai = memoryAi()
    let tokens = 0
    let generating = 0
    const post = directPost(URL)
    const model = new OpenAICompatibleBrain({
      model: MODEL,
      // EVAL_DEBUG=2: also the raw request and answer.
      post: process.env.EVAL_DEBUG !== '2' ? post : async (body, signal) => {
        const response = await post(body, signal)
        const raw = await response.clone().text()
        console.log(`>>> ${JSON.stringify(body).slice(0, 3000)}\n<<< ${raw.slice(0, 1500)}`)
        return response
      },
    })
    // EVAL_DEBUG=1: print each call's stage, its answer and its tool calls.
    const brain: Brain = !process.env.EVAL_DEBUG ? model : {
      async *chat(request) {
        let text = ''
        const calls: string[] = []
        for await (const chunk of model.chat(request)) {
          if (chunk.type === 'text') text += chunk.text
          if (chunk.type === 'tool_call') calls.push(`${chunk.call.name} ${JSON.stringify(chunk.call.args)}`)
          yield chunk
        }
        console.log(`--- ${request.messages[0]!.content.match(/Stage: [^\n]*|The user asks[^\n]*/)?.[0] ?? 'call'}\n${text.slice(0, 600)}\n${calls.join('\n')}`)
      },
    }
    const writer = new StoryWriter({
      brain, ai: ai.ai, store: mem.store, mode: () => 'auto',
      onEvent: (event) => {
        if (event.type === 'usage') {
          tokens += event.tokens
          generating += event.seconds
        }
        if (event.type === 'progress' && event.text) console.log(`  ${brief.id}: ${event.text}`)
      },
    })

    const started = Date.now()
    let error: string | null = null
    try {
      await writer.start({ brief: `${brief.brief} (${brief.genre})`, minutes: brief.minutes, language: brief.language })
    } catch (failure) {
      error = failure instanceof Error ? failure.message : String(failure)
    }
    const seconds = (Date.now() - started) / 1000

    const finalStory = mem.drafts.story!.spec as StorySpec
    const finalScript = mem.drafts.script!.spec as ScriptSpec
    const checks = checkScript(finalStory, finalScript, target(brief.minutes), brief.language)
    const steps = ai.list()
    results.push({
      id: brief.id, language: brief.language, minutes: brief.minutes, seconds, error,
      plan: writer.plan?.state ?? null,
      steps: steps.length,
      reasons_from_model: steps.filter(step => step.reason && !step.reason.startsWith('Step: ') && step.tool !== 'assistant.generate').length,
      failed_steps: steps.filter(step => step.status === 'failed').map(step => `${step.title}: ${step.error}`),
      tokens, tokens_per_second: generating > 0 ? tokens / generating : null,
      runtime_minutes: estimate(finalScript.scenes).minutes,
      checks,
      story: finalStory,
      script: finalScript,
    })
    console.log(`${brief.id}: ${seconds.toFixed(0)} s, ${checks.filter(check => check.ok).length}/${checks.length} checks, plan ${writer.plan?.state}`)
  }

  mkdirSync(`${DIR}/results`, { recursive: true })
  writeFileSync(`${DIR}/results/${MODEL.replace(/[^\w.-]/g, '_')}.json`, JSON.stringify({ model: MODEL, finished_at: new Date().toISOString(), results }, null, 2))
}, 6 * 60 * 60 * 1000)
