<script setup lang="ts">
import {
  ArrowLeftRight, AudioLines, BookOpen, Box, CirclePlay, Clapperboard, File, Film, Folder, Image,
  LayoutDashboard, Library, Megaphone, Mic, Music, Settings, Square, UserRound,
} from '@lucide/vue'

// Lucide icons by name. Only the icons listed here end up in the bundle.
const icons = {
  'arrow-left-right': ArrowLeftRight,
  'audio-lines': AudioLines,
  'book-open': BookOpen,
  'box': Box,
  'circle-play': CirclePlay,
  'clapperboard': Clapperboard,
  'file': File,
  'film': Film,
  'folder': Folder,
  'image': Image,
  'layout-dashboard': LayoutDashboard,
  'library': Library,
  'megaphone': Megaphone,
  'mic': Mic,
  'music': Music,
  'settings': Settings,
  'user-round': UserRound,
} as const

const props = defineProps<{ name: string; size?: number }>()

const icon = computed(() => icons[props.name as keyof typeof icons] ?? Square)
</script>

<template>
  <component :is="icon" :size="size ?? 18" :stroke-width="1.75" aria-hidden="true" />
</template>
