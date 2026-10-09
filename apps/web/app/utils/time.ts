// "just now", "5 min ago", "3 h ago", "2 d ago", then the date.
export function timeAgo(value: string | null): string {
  if (!value) {
    return 'never'
  }
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`
  if (seconds < 7 * 86400) return `${Math.floor(seconds / 86400)} d ago`
  return new Date(value).toLocaleDateString()
}
