import type { JobStatus } from '~/types/api'

export const jobStatusClasses: Record<JobStatus, string> = {
  queued: 'bg-muted/15 text-muted',
  assigned: 'bg-accent/15 text-accent',
  running: 'bg-media/15 text-media',
  paused: 'bg-muted/15 text-muted',
  succeeded: 'bg-green-500/15 text-green-400',
  failed: 'bg-red-500/15 text-red-400',
  cancelled: 'bg-muted/15 text-muted',
}

export const activeJobStatuses: JobStatus[] = ['queued', 'assigned', 'running', 'paused']
