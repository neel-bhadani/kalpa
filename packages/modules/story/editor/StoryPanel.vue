<script setup lang="ts">
// Right side: the story — logline, synopsis, genre, tone, target length, characters and themes.
// Each field changes the story when it's left (one command per edit, not per keystroke).
import { ref } from 'vue'
import type { StorySpec } from '../spec'

const props = defineProps<{ story: StorySpec; readonly?: boolean }>()
const emit = defineEmits<{ run: [name: string, args: Record<string, unknown>] }>()

const newName = ref('')

function setField(field: string, value: unknown) {
  if ((props.story as Record<string, unknown>)[field] === value) return
  emit('run', 'story.setField', { field, value })
}

function value(event: Event): string {
  return (event.target as HTMLInputElement | HTMLTextAreaElement).value
}

function addCharacter() {
  const name = newName.value.trim()
  if (!name) return
  emit('run', 'story.addCharacter', { name: name.toUpperCase() })
  newName.value = ''
}

function setThemes(text: string) {
  const themes = text.split(',').map(theme => theme.trim()).filter(Boolean)
  if (themes.join(',') !== (props.story.themes ?? []).join(',')) emit('run', 'story.setField', { field: 'themes', value: themes })
}
</script>

<template>
  <aside class="flex min-h-0 flex-col gap-3 overflow-y-auto text-sm" aria-label="Story" data-test="story-panel">
    <label class="block text-xs text-muted">Logline
      <textarea :value="story.logline ?? ''" :disabled="readonly" rows="2" maxlength="500" class="field" data-test="story-logline" @change="setField('logline', value($event))" />
    </label>
    <label class="block text-xs text-muted">Synopsis
      <textarea :value="story.synopsis ?? ''" :disabled="readonly" rows="5" maxlength="20000" class="field" data-test="story-synopsis" @change="setField('synopsis', value($event))" />
    </label>
    <div class="grid grid-cols-2 gap-2">
      <label class="block text-xs text-muted">Genre
        <input :value="story.genre ?? ''" :disabled="readonly" maxlength="100" class="field" @change="setField('genre', value($event))">
      </label>
      <label class="block text-xs text-muted">Tone
        <input :value="story.tone ?? ''" :disabled="readonly" maxlength="100" class="field" @change="setField('tone', value($event))">
      </label>
    </div>
    <label class="block text-xs text-muted">Target length (minutes)
      <input :value="story.target_length ?? ''" :disabled="readonly" type="number" min="0" max="600" step="1" class="field font-mono" @change="setField('target_length', Number(value($event)))">
    </label>

    <section>
      <h3 class="text-xs text-muted">Characters</h3>
      <ul class="mt-1 space-y-2">
        <li v-for="character in story.characters ?? []" :key="character.id" class="rounded-lg bg-bg p-2" data-test="story-character">
          <div class="flex gap-2">
            <input
              :value="character.name"
              :disabled="readonly"
              aria-label="Name"
              maxlength="100"
              class="min-w-0 flex-1 bg-transparent font-mono text-xs uppercase outline-none"
              @change="value($event).trim() && emit('run', 'story.updateCharacter', { id: character.id, name: value($event).trim().toUpperCase() })"
            >
            <input
              :value="character.role ?? ''"
              :disabled="readonly"
              aria-label="Role"
              placeholder="role"
              maxlength="100"
              class="w-24 bg-transparent text-xs text-muted outline-none"
              @change="emit('run', 'story.updateCharacter', { id: character.id, role: value($event) })"
            >
            <button v-if="!readonly" class="text-xs text-muted hover:text-red-400" :aria-label="`Remove ${character.name}`" @click="emit('run', 'story.removeCharacter', { id: character.id })">×</button>
          </div>
          <textarea
            :value="character.description ?? ''"
            :disabled="readonly"
            aria-label="Description"
            placeholder="Who they are"
            rows="2"
            maxlength="5000"
            class="mt-1 w-full resize-y bg-transparent text-xs text-muted outline-none"
            @change="emit('run', 'story.updateCharacter', { id: character.id, description: value($event) })"
          />
        </li>
      </ul>
      <form v-if="!readonly" class="mt-2 flex gap-2" @submit.prevent="addCharacter">
        <input v-model="newName" placeholder="New character" maxlength="100" class="field !mt-0 flex-1 text-xs" data-test="new-character">
        <button class="rounded-md border border-muted/30 px-2 text-xs hover:border-accent hover:text-accent">Add</button>
      </form>
    </section>

    <label class="block text-xs text-muted">Themes (comma-separated)
      <input :value="(story.themes ?? []).join(', ')" :disabled="readonly" class="field" data-test="story-themes" @change="setThemes(value($event))">
    </label>
  </aside>
</template>
