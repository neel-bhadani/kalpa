import type { StartResult, StoredPart, UploadApi, UploadedFile, UploadInfo } from '@kalpa/core/upload'

// The upload endpoints, in the shape packages/core's uploader expects.
export function useUploadApi(): UploadApi {
  const api = useApi()

  return {
    async start(body) {
      return (await api<{ data: StartResult }>('/api/v1/uploads', { method: 'POST', body })).data
    },
    async status(uploadId) {
      return (await api<{ data: { upload: UploadInfo; parts: StoredPart[] } }>(`/api/v1/uploads/${uploadId}`)).data
    },
    async presign(uploadId, partNumbers) {
      return (await api<{ data: { urls: { number: number; url: string }[]; expires_at: string } }>(
        `/api/v1/uploads/${uploadId}/parts`,
        { method: 'POST', body: { part_numbers: partNumbers } },
      )).data
    },
    async complete(uploadId, parts) {
      return (await api<{ data: { file: UploadedFile | null; pending_verification: boolean } }>(`/api/v1/uploads/${uploadId}/complete`, {
        method: 'POST',
        body: { parts },
      })).data
    },
    async abort(uploadId) {
      await api(`/api/v1/uploads/${uploadId}`, { method: 'DELETE' })
    },
  }
}
