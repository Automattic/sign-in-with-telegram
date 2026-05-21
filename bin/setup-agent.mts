#!/usr/bin/env node
/**
 * Install per-agent slash-command shims as symlinks pointing at the canonical
 * skill files in `.agents/commands/`.
 *
 * Usage: node bin/setup-agent.mts <agent>
 *   where <agent> is one of: claude, cursor, codex, gemini
 *
 * Executed directly by Node 24+ via native type-stripping. Type-stripping
 * constraints: no enum, no namespace, no parameter-property shorthand, no
 * experimental decorators, use `import type` for type-only imports.
 */

import {
	mkdirSync,
	readdirSync,
	lstatSync,
	unlinkSync,
	symlinkSync,
	copyFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(SCRIPT_DIR, '..');
const CANONICAL_DIR = join(REPO_ROOT, '.agents', 'commands');

type AgentName = 'claude' | 'cursor' | 'codex' | 'gemini';

const AGENT_TARGETS: Record<AgentName, string | null> = {
	claude: '.claude/commands',
	cursor: '.cursor/commands',
	// Codex CLI and Gemini CLI have no documented per-project commands
	// directory today. These entries are placeholders so `npm run setup:codex`
	// and `npm run setup:gemini` exit cleanly with a note rather than error.
	codex: null,
	gemini: null,
};

function isAgentName(value: string): value is AgentName {
	return (
		value === 'claude' ||
		value === 'cursor' ||
		value === 'codex' ||
		value === 'gemini'
	);
}

function main(): void {
	const agent = process.argv[2];

	if (!agent) {
		console.error('Usage: node bin/setup-agent.mts <agent>');
		console.error('Supported agents: claude, cursor, codex, gemini');
		process.exit(1);
	}

	if (!isAgentName(agent)) {
		console.error(
			`Unknown agent "${agent}". Supported: claude, cursor, codex, gemini.`
		);
		process.exit(1);
	}

	const targetRel = AGENT_TARGETS[agent];

	if (targetRel === null) {
		console.log(
			`Agent "${agent}" has no per-project commands directory convention today. ` +
				`AGENTS.md is the convergent agent-doc and is already in place — nothing to do.`
		);
		return;
	}

	const targetDir = join(REPO_ROOT, targetRel);
	mkdirSync(targetDir, { recursive: true });

	const skills = readdirSync(CANONICAL_DIR).filter((name) =>
		name.endsWith('.md')
	);

	if (skills.length === 0) {
		console.warn(
			`No skill files found in ${relative(REPO_ROOT, CANONICAL_DIR)}. Nothing to link.`
		);
		return;
	}

	let linkedCount = 0;
	let copiedCount = 0;

	for (const skill of skills) {
		const source = join(CANONICAL_DIR, skill);
		const dest = join(targetDir, skill);

		// Remove any existing entry (symlink or file) so we re-create from a clean state.
		try {
			lstatSync(dest);
			unlinkSync(dest);
		} catch (err: unknown) {
			if ((err as NodeJS.ErrnoException).code !== 'ENOENT') {
				throw err;
			}
		}

		// Symlink relative path from dest -> source. Relative so the link survives a repo move.
		const relativeSource = relative(dirname(dest), source);

		try {
			symlinkSync(relativeSource, dest);
			linkedCount++;
		} catch (err: unknown) {
			const code = (err as NodeJS.ErrnoException).code;
			if (code === 'EPERM' || code === 'EACCES') {
				// Stock Windows without developer mode can't create symlinks.
				// Fall back to a plain copy so the shims still work — they
				// just won't auto-update when the canonical file changes.
				copyFileSync(source, dest);
				copiedCount++;
			} else {
				throw err;
			}
		}
	}

	if (copiedCount > 0) {
		console.warn(
			`Symlinks not permitted on this platform (${copiedCount} file(s) copied instead). ` +
				`Re-run after enabling developer mode to switch to symlinks.`
		);
	}

	const total = linkedCount + copiedCount;
	console.log(
		`Linked ${total} skill${total === 1 ? '' : 's'} into ${targetRel}/.`
	);
}

main();
