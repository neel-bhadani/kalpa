// The screenplay as one ProseMirror document: a flat list of elements. A heading starts a scene;
// lines belong to the heading before them. Each element keeps its spec id in its attrs.

import { Schema, type Node } from 'prosemirror-model'
import { LINE_TYPES, newId } from '../spec'

export const schema = new Schema({
  nodes: {
    doc: { content: 'heading (heading | line)*' },
    heading: {
      content: 'text*',
      attrs: { id: { default: '' }, summary: { default: '' } },
      defining: true,
      parseDOM: [{ tag: 'h3.sp-heading' }],
      toDOM: node => ['h3', { 'class': 'sp-el sp-heading', 'data-id': node.attrs.id }, 0],
    },
    line: {
      content: 'text*',
      attrs: { id: { default: '' }, type: { default: 'action' }, character_id: { default: null }, emotion: { default: '' } },
      parseDOM: [{ tag: 'p.sp-el', getAttrs: dom => ({ type: LINE_TYPES.find(type => (dom as HTMLElement).classList.contains(`sp-${type}`)) ?? 'action' }) }],
      toDOM: node => ['p', { 'class': `sp-el sp-${node.attrs.type}`, 'data-id': node.attrs.id }, 0],
    },
    text: {},
  },
  marks: {},
})

// The element type of a node: "heading" or its line type.
export function elementType(node: Node): string {
  return node.type.name === 'heading' ? 'heading' : node.attrs.type
}

// A node of the given element type, with a fresh id.
export function element(type: string, text = ''): Node {
  const content = text ? schema.text(text) : undefined
  return type === 'heading'
    ? schema.nodes.heading!.create({ id: newId() }, content)
    : schema.nodes.line!.create({ id: newId(), type }, content)
}
