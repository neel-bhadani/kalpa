// Shapes returned by the Laravel API (/api/v1).

export type CursorPage<T> = {
  data: T[]
  meta: { per_page: number; next_cursor: string | null; prev_cursor: string | null }
}

export type ProjectStyle = '2d' | '3d' | 'realistic' | 'mixed'
export type AspectRatio = '16:9' | '9:16' | '1:1' | '4:5' | '4:3'

export type Project = {
  id: string
  name: string
  description: string | null
  style: ProjectStyle
  aspect_ratio: AspectRatio
  status: 'active' | 'archived'
  last_opened_at: string | null
  created_at: string
  updated_at: string
}

export type TaskStatus =
  | 'draft' | 'in_progress' | 'waiting_job' | 'needs_review'
  | 'paused' | 'failed' | 'completed' | 'archived'

export type TaskMode = 'auto' | 'semi' | 'manual'

export type Task = {
  id: string
  project_id: string
  project_name?: string
  module: string
  module_name: string | null
  kind: string
  kind_label: string | null
  title: string
  status: TaskStatus
  allowed_transitions: TaskStatus[]
  mode: TaskMode
  assigned_to: 'user' | 'assistant'
  depends_on: string[]
  progress: number
  has_unsynced_changes: boolean
  row_version: number
  // Only on single-task routes.
  checkpoint?: unknown
  updated_at: string
}

export type TaskEvent = {
  id: string
  type: string
  from_status: TaskStatus | null
  to_status: TaskStatus | null
  actor_type: 'user' | 'assistant' | 'system'
  data: Record<string, unknown>
  created_at: string
}

export type ServerModule = {
  key: string
  name: string
  icon: string
  version: string
  task_kinds: { key: string; label: string }[]
  enabled: boolean
}

export type Dashboard = {
  data: {
    continue: Task[]
    counts: Record<TaskStatus, number>
    tasks: Task[]
  }
  meta: CursorPage<Task>['meta']
}
