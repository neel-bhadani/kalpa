// JSON Patch (RFC 6902): add, remove, replace and move, applied without changing the document
// given (only the objects on the changed path are copied). Each apply also returns the inverse
// patch, which undoes it exactly.

export type Operation =
  | { op: 'add'; path: string; value: unknown }
  | { op: 'remove'; path: string }
  | { op: 'replace'; path: string; value: unknown }
  | { op: 'move'; from: string; path: string }

type Container = Record<string, unknown> | unknown[]

export function applyPatch<T>(doc: T, ops: Operation[]): { doc: T; inverse: Operation[] } {
  let current: unknown = doc
  const inverse: Operation[] = []
  for (const op of ops) {
    const result = applyOperation(current, op)
    current = result.doc
    inverse.unshift(...result.inverse)
  }
  return { doc: current as T, inverse }
}

function applyOperation(doc: unknown, op: Operation): { doc: unknown; inverse: Operation[] } {
  switch (op.op) {
    case 'add': {
      const parts = parse(op.path)
      const parent = get(doc, parts.slice(0, -1))
      const key = parts.at(-1)!
      if (Array.isArray(parent)) {
        const index = key === '-' ? parent.length : arrayIndex(key, parent.length + 1, op.path)
        return { doc: update(doc, parts.slice(0, -1), list => insert(list as unknown[], index, op.value)), inverse: [{ op: 'remove', path: join([...parts.slice(0, -1), String(index)]) }] }
      }
      const had = isObject(parent) && key in parent
      const inverse: Operation[] = had ? [{ op: 'replace', path: op.path, value: (parent as Record<string, unknown>)[key] }] : [{ op: 'remove', path: op.path }]
      return { doc: update(doc, parts.slice(0, -1), object => ({ ...(object as object), [key]: op.value })), inverse }
    }
    case 'remove': {
      const parts = parse(op.path)
      const old = get(doc, parts)
      const key = parts.at(-1)!
      return {
        doc: update(doc, parts.slice(0, -1), (parent) => {
          if (Array.isArray(parent)) return parent.filter((_, i) => i !== arrayIndex(key, parent.length, op.path))
          const { [key]: _removed, ...rest } = parent as Record<string, unknown>
          return rest
        }),
        inverse: [{ op: 'add', path: op.path, value: old }],
      }
    }
    case 'replace': {
      const parts = parse(op.path)
      const old = get(doc, parts)
      return { doc: update(doc, parts, () => op.value), inverse: [{ op: 'replace', path: op.path, value: old }] }
    }
    case 'move': {
      const value = get(doc, parse(op.from))
      const removed = applyOperation(doc, { op: 'remove', path: op.from })
      const added = applyOperation(removed.doc, { op: 'add', path: op.path, value })
      return { doc: added.doc, inverse: [{ op: 'move', from: op.path, path: op.from }] }
    }
  }
}

// Returns a copy of doc with the value at path replaced by change(value).
function update(doc: unknown, parts: string[], change: (value: Container) => unknown): unknown {
  if (!parts.length) return change(doc as Container)
  const [key, ...rest] = parts as [string, ...string[]]
  if (Array.isArray(doc)) {
    const index = arrayIndex(key, doc.length, key)
    const copy = [...doc]
    copy[index] = update(doc[index], rest, change)
    return copy
  }
  if (!isObject(doc) || !(key in doc)) throw new Error(`Nothing at "${key}".`)
  return { ...doc, [key]: update(doc[key], rest, change) }
}

export function get(doc: unknown, parts: string[]): unknown {
  let node = doc
  for (const key of parts) {
    if (Array.isArray(node)) node = node[arrayIndex(key, node.length, key)]
    else if (isObject(node) && key in node) node = node[key]
    else throw new Error(`Nothing at "/${parts.join('/')}".`)
  }
  return node
}

function insert(list: unknown[], index: number, value: unknown): unknown[] {
  return [...list.slice(0, index), value, ...list.slice(index)]
}

function arrayIndex(key: string, length: number, path: string): number {
  const index = Number(key)
  if (!/^\d+$/.test(key) || index >= length) throw new Error(`No item ${key} at "${path}".`)
  return index
}

function parse(path: string): string[] {
  if (path === '') return []
  if (!path.startsWith('/')) throw new Error(`Bad path "${path}".`)
  return path.slice(1).split('/').map(part => part.replace(/~1/g, '/').replace(/~0/g, '~'))
}

export function join(parts: (string | number)[]): string {
  return parts.map(part => `/${String(part).replace(/~/g, '~0').replace(/\//g, '~1')}`).join('')
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

// Consecutive sets of the same object key keep only the last (typing in one line makes many).
// An add into a list inserts, so those are never merged.
export function compact(ops: Operation[]): Operation[] {
  const result: Operation[] = []
  for (const op of ops) {
    const last = result.at(-1)
    const sets = (item: Operation | undefined) => (item?.op === 'add' || item?.op === 'replace') && !/\/(\d+|-)$/.test(item.path)
    if (sets(op) && sets(last) && last!.path === op.path) {
      // An add that made the key stays an add.
      result[result.length - 1] = { ...(op as { value: unknown }), op: last!.op, path: op.path } as Operation
    } else {
      result.push(op)
    }
  }
  return result
}
