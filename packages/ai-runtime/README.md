# @kalpa/ai-runtime — the assistant loop: a Brain streams text and tool calls, each tool call becomes a
visible ai_step that runs a module command (the same code the UI uses), with review, undo and retry.
Framework-free TypeScript, runs in the browser. FakeBrain is scripted for development and tests; real
brains (local Qwen3, the user's providers) arrive in P1.
