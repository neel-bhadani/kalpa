// A scripted brain for the Story assistant (development, tests and e2e): it reads which stage it's
// asked for and answers the way a good model would, about a girl in Surat who fixes old radios.

import { abortError, type Brain, type BrainChunk, type ChatRequest } from '../../../ai-runtime/src/brain'

export type StoryFakeOptions = { delayMs?: number }

export class StoryFakeBrain implements Brain {
  private ids = 0

  constructor(private options: StoryFakeOptions = {}) {}

  async *chat({ messages, signal }: ChatRequest): AsyncIterable<BrainChunk> {
    const system = messages[0]?.content ?? ''
    const user = messages.filter(message => message.role === 'user').at(-1)?.content ?? ''
    const stage = system.match(/Stage: ([A-Za-z ]+?)(?:[.,]| scene)/)?.[1]?.trim() ?? 'chat'
    const { text, calls } = this.answer(stage, system, user)

    for (const word of text.split(/(?<=\s)/)) {
      await this.pause(signal)
      if (word) yield { type: 'text', text: word }
    }
    for (const [name, args] of calls) {
      await this.pause(signal)
      yield { type: 'tool_call', call: { id: `fake_${++this.ids}`, name, args } }
    }
  }

  private answer(stage: string, system: string, user: string): { text: string; calls: [string, Record<string, unknown>][] } {
    switch (stage) {
      case 'Brief':
        return { text: '', calls: [['story.setBrief', { title: 'The Radio Mender of Surat', genre: 'Drama', tone: 'Warm, hopeful', audience: 'Family', reason: 'Setting up a warm family drama from your brief' }]] }
      case 'Logline':
        return {
          text: [
            'REASON: One clear goal and a deadline give the short its drive',
            'LOGLINE: A girl in Surat who repairs old radios must fix her grandfather\'s silent set before the monsoon festival.',
            'SYNOPSIS: Asha repairs radios in her father\'s shop. Her grandfather\'s old radio has gone silent.',
            'She hunts for a valve across the city. On festival night the radio plays again.',
          ].join('\n'),
          calls: [],
        }
      case 'Characters':
        return {
          text: '',
          calls: [
            ['story.addCharacter', { name: 'Asha', role: 'lead', description: 'A 12-year-old who fixes radios and wants her grandfather to hear music again.', reason: 'The lead, with a clear want' }],
            ['story.addCharacter', { name: 'Dada', role: 'grandfather', description: 'A quiet man who misses the voice of his old radio.', reason: 'Someone for Asha to fix it for' }],
          ],
        }
      case 'Outline':
        return {
          text: '',
          calls: [
            ['story.addAct', { title: 'The silent radio', summary: 'Dada\'s radio stops. Asha promises to fix it.', reason: 'Setting up the problem' }],
            ['story.addAct', { title: 'The hunt', summary: 'Asha searches the city for a missing valve.', reason: 'Raising the stakes' }],
            ['story.addAct', { title: 'Festival night', summary: 'The radio plays and Dada smiles.', reason: 'Paying off the promise' }],
          ],
        }
      case 'Scene list': {
        const count = Number(system.match(/exactly (\d+) scenes/)?.[1] ?? 3)
        const places = ['INT. RADIO SHOP - DAY', 'EXT. SURAT MARKET - DAY', 'INT. DADA\'S ROOM - NIGHT', 'EXT. ROOFTOP - NIGHT']
        return {
          text: '',
          calls: Array.from({ length: count }, (_, i) => ['script.addScene', { heading: places[i % places.length], summary: `Asha takes step ${i + 1} towards fixing the radio.`, reason: i === 0 ? 'Opening on Asha at work' : 'Moving the hunt forward' }] as [string, Record<string, unknown>]),
        }
      }
      case 'Draft': {
        const number = system.match(/Draft scene (\d+)/)?.[1] ?? '1'
        return {
          text: [
            `REASON: Scene ${number} shows Asha's stubborn hope`,
            `SUMMARY: Asha works on the radio in scene ${number}.`,
            'ACTION: Asha bends over the old radio, a screwdriver in her teeth.',
            'ASHA: It only needs one valve, Dada.',
            'DADA (softly): Then find it, little one.',
            'ACTION: She smiles and pockets the dead valve.',
            ...(user.includes('funnier') ? ['ASHA: Even the radio is tired of your news, Dada!'] : []),
          ].join('\n'),
          calls: [],
        }
      }
      case 'Polish dialogue':
        // Line 3 is Asha's first dialogue line (1 is action, 2 her name).
        return { text: 'REASON: Shorter, warmer lines\n3: One valve. That\'s all, Dada.', calls: [] }
      case 'Review':
        return { text: '- The goal is clear from the first scene.\n- Give Dada one more moment of his own.', calls: [] }
      default: {
        // Chat requests.
        const scene = user.match(/asks:[^\n]*\bscene (\d+)/i)?.[1]
        if (/funnier/i.test(user) && scene) return { text: 'Making it funnier.', calls: [['script.rewriteScene', { scene: Number(scene), instruction: 'make it funnier', reason: 'You asked for more laughs in this scene' }]] }
        if (/add a scene/i.test(user)) return { text: 'Adding the scene.', calls: [['script.addScene', { heading: 'EXT. RIVERFRONT - EVENING', summary: 'Asha and a stranger meet by the river.', reason: 'You asked for a meeting scene' }]] }
        return { text: 'I can rewrite or add scenes. Try "make scene 1 funnier".', calls: [] }
      }
    }
  }

  private async pause(signal?: AbortSignal): Promise<void> {
    if (signal?.aborted) throw abortError()
    const ms = this.options.delayMs ?? 0
    if (ms <= 0) return
    await new Promise<void>((resolve, reject) => {
      const timer = setTimeout(resolve, ms)
      signal?.addEventListener('abort', () => {
        clearTimeout(timer)
        reject(abortError())
      }, { once: true })
    })
  }
}
