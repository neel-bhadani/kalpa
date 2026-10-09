import type { FetchError } from 'ofetch'

// Turns a Laravel error response into a general message plus messages per field.
export function useFormErrors() {
  const message = ref('')
  const fields = ref<Record<string, string>>({})

  function clear() {
    message.value = ''
    fields.value = {}
  }

  function show(error: unknown) {
    const { status, data } = error as FetchError
    clear()

    if (status === 422 && data?.errors) {
      for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
        fields.value[field] = messages[0] ?? ''
      }
    } else if (status === 429) {
      message.value = 'Too many attempts. Please wait a minute and try again.'
    } else {
      message.value = 'Something went wrong. Please try again.'
    }
  }

  return { message, fields, clear, show }
}
