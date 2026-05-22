/**
 * `sync-changelog` — regenerate readme.txt's == Changelog == section
 * from CHANGELOG.md.
 *
 * Part of the bin/cli/ maintenance CLI.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { CHANGELOG, CHANGELOG_URL, fail, README } from './common.mts';

/**
 * Render the wp.org `== Changelog ==` body from CHANGELOG.md.
 *
 * readme.txt carries only the newest release — a long changelog bloats
 * the wp.org plugin page, and wp.org itself recommends linking out for
 * older entries. The full history stays in CHANGELOG.md, linked at the
 * end of the rendered body.
 *
 * CHANGELOG.md keeps release-please's developer-facing detail — category
 * subheadings and the PR / commit links appended to each line. readme.txt
 * is user-facing, so each entry is stripped of that metadata and tidied
 * into a plain, sentence-cased, full-stopped bullet. Spacing is fully
 * controlled here: one blank line after the `= x.y.z =` heading, none
 * between bullets.
 *
 * @param changelog The CHANGELOG.md contents.
 */
export function renderChangelog(changelog: string): string {
	const out: string[] = [];
	let versionCount = 0;

	for (const rawLine of changelog.split('\n')) {
		// Strip markdown links, then the trailing PR / commit references
		// release-please appends, e.g. "([#20](url)) ([acc576b](url))".
		const line = rawLine
			.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
			.replace(/ *\(#\d+\)/g, '')
			.replace(/ *\([0-9a-f]{7,40}\)/g, '');

		if (/^# Changelog\s*$/.test(line) || /^### /.test(line)) {
			continue;
		}

		const heading = line.match(/^## +(.*)$/);
		if (heading) {
			versionCount += 1;
			// Only the newest release goes in readme.txt; stop once the
			// second version heading appears.
			if (versionCount > 1) {
				break;
			}
			const bracketed = heading[1].match(/\[([^\]]+)\]/);
			const version = bracketed
				? bracketed[1]
				: heading[1].split(/\s/)[0];
			out.push(`= ${version} =`, '');
			continue;
		}

		const bullet = line.match(/^[*-] +(.*)$/);
		if (bullet) {
			let entry = bullet[1].trim();
			if (entry === '') {
				continue;
			}
			// Sentence-case a leading lowercase ASCII letter.
			if (/[a-z]/.test(entry[0])) {
				entry = entry[0].toUpperCase() + entry.slice(1);
			}
			// Terminal period unless already sentence-punctuated.
			if (!/[.!?]$/.test(entry)) {
				entry += '.';
			}
			out.push(`* ${entry}`);
		}
		// Everything else (blank lines, stray prose) is dropped — spacing
		// is reconstructed above.
	}

	// A drifted CHANGELOG.md — renamed headings, a release-please format
	// change — would parse to zero entries and silently reduce readme.txt's
	// == Changelog == section to just the GitHub link. Fail loudly instead.
	if (versionCount === 0) {
		fail(
			'No release entries found in CHANGELOG.md — refusing to rewrite readme.txt.'
		);
	}

	out.push(
		'',
		`For the full version history, see [the changelog on GitHub](${CHANGELOG_URL}).`
	);

	return out.join('\n');
}

/**
 * Replace readme.txt's `== Changelog ==` section with a freshly rendered
 * body. The section runs to the next `== Heading ==` line or end of file.
 * Fails if there's no `== Changelog ==` heading — a renamed or deleted
 * section would otherwise let the CI drift guard pass silently while the
 * changelog stops being maintained.
 *
 * @param readme The current readme.txt contents.
 * @param body   The rendered == Changelog == body to splice in.
 */
export function spliceChangelogSection(readme: string, body: string): string {
	const out: string[] = [];
	let inSection = false;
	let matched = false;

	for (const line of readme.split('\n')) {
		if (!matched && /^== Changelog ==\s*$/.test(line)) {
			out.push('== Changelog ==', '', ...body.split('\n'));
			inSection = true;
			matched = true;
			continue;
		}
		if (inSection && /^== /.test(line)) {
			inSection = false;
		}
		if (!inSection) {
			out.push(line);
		}
	}

	if (!matched) {
		fail('Could not locate a == Changelog == section in readme.txt');
	}

	// Normalize to exactly one trailing newline.
	return `${out.join('\n').replace(/\n*$/, '')}\n`;
}

/** Run the `sync-changelog` command. */
export function syncChangelog(): void {
	const original = readFileSync(README, 'utf8');
	const body = renderChangelog(readFileSync(CHANGELOG, 'utf8'));
	const updated = spliceChangelogSection(original, body);

	if (updated !== original) {
		writeFileSync(README, updated);
		console.log('readme.txt updated.');
	} else {
		console.log('readme.txt already in sync.');
	}
}
