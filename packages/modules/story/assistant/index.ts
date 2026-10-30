// The Story assistant, loaded with the panel when a story task is open (its own chunk).
import type { ModuleAssistantFactory } from '../../../ai-runtime/src/module-assistant'
import { StoryFakeBrain } from './fake-brain'
import { checkpointStore } from './store'
import { StoryWriter, type Plan } from './writer'

const factory: ModuleAssistantFactory = {
  createAssistant: context => new StoryWriter({
    brain: context.brain,
    ai: context.ai,
    store: checkpointStore(context),
    mode: context.mode,
    plan: context.plan as Partial<Plan> | null,
    onEvent: context.onEvent,
    // A short pause between Auto steps, so each one can be seen landing in the editor.
    stepDelayMs: 400,
  }),
  fakeBrain: () => new StoryFakeBrain({ delayMs: 15 }),
}

export default factory
