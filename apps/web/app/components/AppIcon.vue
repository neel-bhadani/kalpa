<script setup lang="ts">
import {
  ArrowLeftRight, BookOpen, Box, CirclePlay, Clapperboard, Folder, LayoutDashboard,
  Megaphone, Mic, Music, Settings, Square, UserRound,
} from '@lucide/vue'

// Lucide icons by name. Only the icons listed here end up in the bundle.
const icons = {
  'arrow-left-right': ArrowLeftRight,
  'book-open': BookOpen,
  'box': Box,
  'circle-play': CirclePlay,
  'clapperboard': Clapperboard,
  'folder': Folder,
  'layout-dashboard': LayoutDashboard,
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
