import type { TaskStatus } from '~/types/api'

// How each status looks to the user. Several statuses can share a badge (draft and paused are both "Pending").
export type StatusBadge = { label: string; classes: string }

const grey = 'bg-muted/15 text-muted'

export const statusBadges: Record<TaskStatus, StatusBadge> = {
  draft: { label: 'Pending', classes: grey },
  paused: { label: 'Pending', classes: grey },
  in_progress: { label: 'In progress', classes: 'bg-accent/15 text-accent' },
  waiting_job: { label: 'Processing', classes: 'bg-media/15 text-media' },
  needs_review: { label: 'Needs review', classes: 'bg-amber-400/15 text-amber-300' },
  failed: { label: 'Failed', classes: 'bg-red-500/15 text-red-400' },
  completed: { label: 'Complete', classes: 'bg-green-500/15 text-green-400' },
  archived: { label: 'Archived', classes: grey },
}

// The status filter chips, each covering one or more statuses.
export const statusFilters: { label: string; statuses: TaskStatus[] }[] = [
  { label: 'Pending', statuses: ['draft', 'paused'] },
  { label: 'In progress', statuses: ['in_progress'] },
  { label: 'Processing', statuses: ['waiting_job'] },
  { label: 'Needs review', statuses: ['needs_review'] },
  { label: 'Failed', statuses: ['failed'] },
  { label: 'Complete', statuses: ['completed'] },
  { label: 'Archived', statuses: ['archived'] },
]
