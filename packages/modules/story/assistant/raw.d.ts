// The stage instructions are plain text files, imported as strings.
declare module '*.txt?raw' {
  const text: string
  export default text
}
